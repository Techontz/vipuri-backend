<?php

namespace Tests\Feature;

use App\Constants\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    public function test_a_customer_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'firstname' => 'Asha',
            'lastname' => 'Mwinyi',
            'username' => 'asha.test',
            'email' => 'asha.test@example.co.tz',
            'password' => 'Testing@2026',
            'password_confirmation' => 'Testing@2026',
            'agree' => true,
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('users', ['email' => 'asha.test@example.co.tz']);
    }

    public function test_registration_is_blocked_when_the_store_disables_it(): void
    {
        \App\Models\GeneralSetting::current()->update(['registration' => 0]);
        \App\Models\GeneralSetting::flush();

        $this->postJson('/api/v1/auth/register', [
            'firstname' => 'Blocked',
            'lastname' => 'User',
            'username' => 'blocked.user',
            'email' => 'blocked@example.co.tz',
            'password' => 'Testing@2026',
            'password_confirmation' => 'Testing@2026',
            'agree' => true,
        ])->assertStatus(422)->assertJsonPath('remark', 'registration_disabled');
    }

    public function test_a_customer_can_log_in_and_read_their_profile(): void
    {
        $customer = $this->makeCustomer(['username' => 'login.test']);

        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'login.test',
            'password' => 'Testing@2026',
        ])->assertOk();

        $token = $login->json('data.token');

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $customer->email);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        $this->makeCustomer(['username' => 'wrong.pass']);

        $this->postJson('/api/v1/auth/login', ['username' => 'wrong.pass', 'password' => 'nope'])
            ->assertStatus(401)
            ->assertJsonPath('remark', 'invalid_credentials');
    }

    public function test_a_suspended_customer_cannot_log_in(): void
    {
        $this->makeCustomer(['username' => 'banned.user', 'status' => 0, 'ban_reason' => 'Fraud']);

        $this->postJson('/api/v1/auth/login', ['username' => 'banned.user', 'password' => 'Testing@2026'])
            ->assertStatus(403)
            ->assertJsonPath('remark', 'account_banned');
    }

    public function test_customer_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/user/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/user/orders')->assertStatus(401);
    }

    public function test_a_customer_token_cannot_reach_the_admin_api(): void
    {
        $customer = $this->makeCustomer();

        $this->withHeaders($this->userHeaders($customer))
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(401);
    }

    public function test_staff_can_log_in_and_receive_their_permissions(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN, attributes: ['username' => 'super.test']);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'username' => 'super.test',
            'password' => 'Testing@2026',
        ])->assertOk();

        $this->assertTrue($response->json('data.admin.is_super_admin'));
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame($admin->email, $response->json('data.admin.email'));
    }

    public function test_a_deactivated_staff_account_is_rejected(): void
    {
        $this->makeStaff(Roles::BRANCH_WORKER, attributes: ['username' => 'off.worker', 'status' => 0]);

        $this->postJson('/api/v1/admin/auth/login', ['username' => 'off.worker', 'password' => 'Testing@2026'])
            ->assertStatus(403)
            ->assertJsonPath('remark', 'account_disabled');
    }

    public function test_a_staff_token_cannot_reach_the_customer_api(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($admin))
            ->getJson('/api/v1/user/dashboard')
            ->assertStatus(401);
    }

    public function test_password_reset_does_not_reveal_whether_an_account_exists(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.co.tz'])
            ->assertOk()
            ->assertJsonPath('remark', 'reset_code_sent');
    }

    /**
     * A browser hitting an API URL sends `Accept: text/html`, which used to
     * send Laravel looking for a `login` route that an API-only application
     * does not have — a 500 with a stack trace instead of a plain refusal.
     */
    public function test_a_protected_endpoint_answers_401_even_without_a_json_accept_header(): void
    {
        foreach (['/api/v1/admin/branches', '/api/v1/user/dashboard'] as $path) {
            $this->get($path, ['Accept' => 'text/html'])
                ->assertUnauthorized()
                ->assertJsonPath('remark', 'unauthenticated');
        }
    }

    public function test_a_customer_token_is_not_accepted_by_the_staff_api(): void
    {
        $customer = $this->makeCustomer();

        foreach (['/api/v1/admin/branches', '/api/v1/admin/staff', '/api/v1/admin/settings/general'] as $path) {
            $this->withHeaders($this->userHeaders($customer))
                ->getJson($path)
                ->assertUnauthorized();
        }
    }
}
