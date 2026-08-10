<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Constants\Status;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The security backbone of the VIPURI model: staff must never read or write
 * data belonging to another branch, no matter what ids they send.
 */
class BranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->branchA = Branch::where('code', 'DSM-01')->firstOrFail();
        $this->branchB = Branch::where('code', 'ARU-01')->firstOrFail();
    }

    private function makeOrder(Branch $branch): Order
    {
        $product = $this->makeProduct($branch);

        $order = Order::create([
            'company_id' => Company::current()->id,
            'branch_id' => $branch->id,
            'order_number' => 'VP' . strtoupper(uniqid()),
            'user_id' => 0,
            'guest_id' => 0,
            'status' => Status::ORDER_PENDING,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal' => 100000,
            'total' => 100000,
            'shipping_address' => ['city' => 'Dar es Salaam'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variation_id' => 0,
            'quantity' => 1,
            'price' => 100000,
            'subtotal' => 100000,
        ]);

        return $order;
    }

    public function test_a_worker_only_sees_orders_from_their_own_branch(): void
    {
        $this->makeOrder($this->branchA);
        $this->makeOrder($this->branchB);

        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branchA);

        $response = $this->withHeaders($this->adminHeaders($worker))
            ->getJson('/api/v1/admin/orders')
            ->assertOk();

        $branchIds = collect($response->json('data.orders'))->pluck('branch.id')->unique()->values()->all();

        $this->assertSame([$this->branchA->id], $branchIds);
        $this->assertSame(1, $response->json('data.pagination.total'));
    }

    public function test_a_worker_cannot_open_another_branchs_order(): void
    {
        $order = $this->makeOrder($this->branchB);
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branchA);

        $this->withHeaders($this->adminHeaders($worker))
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertStatus(403);
    }

    public function test_a_worker_cannot_change_the_status_of_another_branchs_order(): void
    {
        $order = $this->makeOrder($this->branchB);
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branchA);

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_DELIVERED])
            ->assertStatus(403);

        $this->assertSame(Status::ORDER_PENDING, $order->fresh()->status);
    }

    public function test_a_worker_cannot_read_another_branchs_inventory(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branchA);

        $this->withHeaders($this->adminHeaders($worker))
            ->getJson("/api/v1/admin/inventory?branch_id={$this->branchB->id}")
            ->assertStatus(403);
    }

    public function test_a_worker_cannot_adjust_stock_at_another_branch(): void
    {
        $product = $this->makeProduct($this->branchB, 5);
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branchA);

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson('/api/v1/admin/inventory/adjust', [
                'branch_id' => $this->branchB->id,
                'product_id' => $product->id,
                'mode' => 'delta',
                'quantity' => 500,
            ])
            ->assertStatus(403);

        $this->assertSame(5, (int) $product->branchInventories()->where('branch_id', $this->branchB->id)->value('stock_quantity'));
    }

    public function test_a_branch_manager_cannot_create_a_branch(): void
    {
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branchA);

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson('/api/v1/admin/branches', ['name' => 'Rogue branch', 'code' => 'RG-01'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('branches', ['code' => 'RG-01']);
    }

    public function test_a_branch_manager_cannot_edit_staff_in_another_branch(): void
    {
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branchA);
        $otherWorker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branchB);

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson("/api/v1/admin/staff/{$otherWorker->id}", [
                'name' => 'Hijacked',
                'email' => 'hijack@vipuri.test',
                'username' => 'hijacked',
            ])
            ->assertStatus(403);

        $this->assertNotSame('Hijacked', $otherWorker->fresh()->name);
    }

    public function test_a_branch_manager_cannot_promote_a_worker_to_super_admin(): void
    {
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branchA);

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson('/api/v1/admin/staff', [
                'name' => 'Sneaky Admin',
                'email' => 'sneaky@vipuri.test',
                'username' => 'sneaky',
                'password' => 'Testing@2026',
                'password_confirmation' => 'Testing@2026',
                'role' => Roles::SUPER_ADMIN,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('admins', ['username' => 'sneaky']);
    }

    public function test_staff_created_by_a_manager_join_the_managers_branch(): void
    {
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branchA);

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson('/api/v1/admin/staff', [
                'name' => 'New Worker',
                'email' => 'new.worker@vipuri.test',
                'username' => 'new.worker',
                'password' => 'Testing@2026',
                'password_confirmation' => 'Testing@2026',
                'role' => Roles::BRANCH_WORKER,
                // Deliberately asks for another branch; the API must ignore it.
                'branch_id' => $this->branchB->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('admins', [
            'username' => 'new.worker',
            'branch_id' => $this->branchA->id,
        ]);
    }

    public function test_a_manager_cannot_enumerate_super_admins(): void
    {
        $this->makeStaff(Roles::SUPER_ADMIN, attributes: ['username' => 'hidden.super']);
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branchA);

        $response = $this->withHeaders($this->adminHeaders($manager))
            ->getJson('/api/v1/admin/staff')
            ->assertOk();

        $usernames = collect($response->json('data.staff'))->pluck('username')->all();

        $this->assertNotContains('hidden.super', $usernames);
    }

    public function test_a_super_admin_sees_every_branch(): void
    {
        $this->makeOrder($this->branchA);
        $this->makeOrder($this->branchB);

        $super = $this->makeStaff(Roles::SUPER_ADMIN);

        $response = $this->withHeaders($this->adminHeaders($super))
            ->getJson('/api/v1/admin/orders')
            ->assertOk();

        $this->assertSame(2, $response->json('data.pagination.total'));
        $this->assertSame('company', $this->withHeaders($this->adminHeaders($super))
            ->getJson('/api/v1/admin/dashboard')
            ->json('data.scope'));
    }

    public function test_a_workers_dashboard_is_scoped_to_their_branch(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branchA);

        $response = $this->withHeaders($this->adminHeaders($worker))
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $this->assertSame('branch', $response->json('data.scope'));
        $this->assertSame($this->branchA->id, $response->json('data.branch.id'));
    }

    public function test_a_worker_cannot_reassign_an_order_to_another_branch(): void
    {
        $order = $this->makeOrder($this->branchA);
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branchA);

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/branch", ['branch_id' => $this->branchB->id])
            ->assertStatus(403);
    }

    public function test_a_worker_cannot_reach_settings_or_reports_they_lack_permission_for(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branchA);
        $headers = $this->adminHeaders($worker);

        $this->withHeaders($headers)->getJson('/api/v1/admin/settings/general')->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/v1/admin/reports/audit-logs')->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/v1/admin/gateways')->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/v1/admin/coupons')->assertStatus(403);
    }
}
