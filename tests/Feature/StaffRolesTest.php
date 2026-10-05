<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Constants\Status;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Custom roles, ranks and company-wide admins: who may create whom, where,
 * and with which permissions.
 */
class StaffRolesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $dsm;

    private Branch $dodoma;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->dsm = Branch::where('code', 'DSM-01')->firstOrFail();
        $this->dodoma = Branch::where('code', 'DOM-01')->firstOrFail();
    }

    private function staffPayload(string $role, array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return array_merge([
            'name' => "New Person $n",
            'email' => "new{$n}@vipuri.test",
            'username' => "new{$n}",
            'password' => 'Testing@2026',
            'password_confirmation' => 'Testing@2026',
            'role' => $role,
        ], $overrides);
    }

    private function companyAdmin(): Admin
    {
        return $this->makeStaff(Roles::ADMIN, null, ['branch_id' => null]);
    }

    public function test_the_built_in_roles_exist_with_their_ranks(): void
    {
        foreach (Roles::ALL as $name) {
            $role = Role::where('name', $name)->firstOrFail();
            $this->assertSame(Roles::LEVELS[$name], (int) $role->level, $name);
        }
    }

    public function test_an_hr_officer_can_hire_sales_staff_into_their_own_branch_only(): void
    {
        $hr = $this->makeStaff(Roles::HR_OFFICER, $this->dodoma);

        $response = $this->withHeaders($this->adminHeaders($hr))
            ->postJson('/api/v1/admin/staff', $this->staffPayload(Roles::SALES_ASSISTANT, ['branch_id' => $this->dsm->id]))
            ->assertOk();

        // Placed in HR's branch whatever branch was sent.
        $this->assertSame($this->dodoma->id, $response->json('data.staff.branch_id'));

        // A manager ranks above HR, so HR cannot create one.
        $this->withHeaders($this->adminHeaders($hr))
            ->postJson('/api/v1/admin/staff', $this->staffPayload(Roles::BRANCH_MANAGER))
            ->assertStatus(422);
    }

    public function test_an_hr_officer_cannot_manage_staff_in_another_branch_or_above_them(): void
    {
        $hr = $this->makeStaff(Roles::HR_OFFICER, $this->dodoma);
        $otherBranchSales = $this->makeStaff(Roles::SALES_ASSISTANT, $this->dsm);
        $ownManager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->dodoma);

        $this->withHeaders($this->adminHeaders($hr))->getJson("/api/v1/admin/staff/{$otherBranchSales->id}")->assertForbidden();
        $this->withHeaders($this->adminHeaders($hr))->getJson("/api/v1/admin/staff/{$ownManager->id}")->assertForbidden();
    }

    public function test_a_manager_can_hire_hr_and_sales_but_not_another_manager(): void
    {
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->dodoma);
        $headers = $this->adminHeaders($manager);

        $this->withHeaders($headers)->postJson('/api/v1/admin/staff', $this->staffPayload(Roles::HR_OFFICER))->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/admin/staff', $this->staffPayload(Roles::SALES_ASSISTANT))->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/admin/staff', $this->staffPayload(Roles::BRANCH_MANAGER))->assertStatus(422);
    }

    public function test_a_company_wide_admin_needs_no_branch_and_sees_every_branch(): void
    {
        foreach ([$this->dsm, $this->dodoma] as $branch) {
            Order::create([
                'company_id' => Company::current()->id,
                'branch_id' => $branch->id,
                'order_number' => 'VP' . strtoupper(uniqid()),
                'user_id' => 0,
                'guest_id' => 0,
                'status' => Status::ORDER_PENDING,
                'payment_status' => Status::PAYMENT_PENDING,
                'subtotal' => 1000,
                'total' => 1000,
                'shipping_address' => ['city' => 'Dodoma'],
            ]);
        }

        $admin = $this->companyAdmin();

        $this->withHeaders($this->adminHeaders($admin))
            ->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_a_company_wide_admin_can_staff_any_branch_but_cannot_create_a_super_admin(): void
    {
        $admin = $this->companyAdmin();
        $headers = $this->adminHeaders($admin);

        $this->withHeaders($headers)
            ->postJson('/api/v1/admin/staff', $this->staffPayload(Roles::BRANCH_MANAGER, ['branch_id' => $this->dodoma->id]))
            ->assertOk()
            ->assertJsonPath('data.staff.branch_id', $this->dodoma->id);

        $this->withHeaders($headers)
            ->postJson('/api/v1/admin/staff', $this->staffPayload(Roles::SUPER_ADMIN))
            ->assertStatus(422);
    }

    public function test_an_admin_does_not_hold_system_configuration(): void
    {
        $admin = $this->companyAdmin();

        $this->assertFalse($admin->can('setting.system'));
        $this->assertTrue($admin->can('branch.all'));
        $this->assertTrue($admin->isCompanyWide());
    }

    public function test_a_role_can_be_created_only_below_your_rank_and_with_permissions_you_hold(): void
    {
        $admin = $this->companyAdmin();
        $headers = $this->adminHeaders($admin);

        $this->withHeaders($headers)->postJson('/api/v1/admin/staff/roles', [
            'name' => 'Storekeeper',
            'level' => 30,
            'permissions' => ['inventory.view', 'inventory.receive', 'inventory.adjust'],
        ])->assertOk();

        $this->assertSame(30, (int) Role::where('name', 'Storekeeper')->first()->level);

        // At or above the admin's own rank: refused.
        $this->withHeaders($headers)->postJson('/api/v1/admin/staff/roles', [
            'name' => 'Deputy', 'level' => 95, 'permissions' => ['inventory.view'],
        ])->assertStatus(422);

        // A permission the admin does not hold: refused.
        $this->withHeaders($headers)->postJson('/api/v1/admin/staff/roles', [
            'name' => 'Sysop', 'level' => 10, 'permissions' => ['setting.system'],
        ])->assertStatus(422);
    }

    public function test_a_custom_role_can_be_assigned_and_cannot_be_deleted_while_in_use(): void
    {
        $admin = $this->companyAdmin();
        $headers = $this->adminHeaders($admin);

        $roleId = $this->withHeaders($headers)->postJson('/api/v1/admin/staff/roles', [
            'name' => 'Cashier', 'level' => 15, 'permissions' => ['pos.sell', 'product.view'],
        ])->json('data.role.id');

        $this->withHeaders($headers)
            ->postJson('/api/v1/admin/staff', $this->staffPayload('Cashier', ['branch_id' => $this->dodoma->id]))
            ->assertOk();

        $this->withHeaders($headers)->deleteJson("/api/v1/admin/staff/roles/{$roleId}")->assertJsonPath('remark', 'role_in_use');

        // Built-in roles can never be deleted.
        $builtIn = Role::where('name', Roles::SALES_ASSISTANT)->first()->id;
        $this->withHeaders($headers)->deleteJson("/api/v1/admin/staff/roles/{$builtIn}")->assertJsonPath('remark', 'protected_role');
    }

    public function test_branch_staff_cannot_manage_roles(): void
    {
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->dodoma);

        $this->withHeaders($this->adminHeaders($manager))->postJson('/api/v1/admin/staff/roles', [
            'name' => 'Whatever', 'level' => 5, 'permissions' => [],
        ])->assertForbidden();
    }

    public function test_nobody_can_change_their_own_role(): void
    {
        $admin = $this->companyAdmin();

        $this->withHeaders($this->adminHeaders($admin))->postJson("/api/v1/admin/staff/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'username' => $admin->username,
            'role' => Roles::BRANCH_MANAGER,
        ])->assertForbidden();
    }

    public function test_refusals_explain_themselves_even_with_debug_off(): void
    {
        config(['app.debug' => false]);

        $hr = $this->makeStaff(Roles::HR_OFFICER, $this->dodoma);
        $otherBranchSales = $this->makeStaff(Roles::SALES_ASSISTANT, $this->dsm);

        $this->withHeaders($this->adminHeaders($hr))
            ->getJson("/api/v1/admin/staff/{$otherBranchSales->id}")
            ->assertForbidden()
            ->assertJsonPath('message.error.0', 'You can only manage staff ranked below you in your own branch');

        // A company-wide caller giving someone a one-branch role must pick a branch.
        $this->withHeaders($this->adminHeaders($this->companyAdmin()))
            ->postJson('/api/v1/admin/staff', $this->staffPayload(Roles::SALES_ASSISTANT))
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_the_roles_screen_gets_what_it_needs_to_explain_ranks(): void
    {
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->dodoma);

        $response = $this->withHeaders($this->adminHeaders($manager))
            ->getJson('/api/v1/admin/staff/roles')
            ->assertOk()
            ->assertJsonPath('data.my_level', 60);

        // Only roles ranked below a manager, and none that span branches.
        $this->assertEqualsCanonicalizing(
            [Roles::HR_OFFICER, Roles::SALES_ASSISTANT, Roles::BRANCH_WORKER],
            $response->json('data.assignable_roles'),
        );

        // Managers hold no role.manage, so nothing is editable for them.
        $this->assertSame([], collect($response->json('data.roles'))->where('can_edit', true)->values()->all());
    }
}
