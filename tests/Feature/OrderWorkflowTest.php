<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Constants\Status;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Guest;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * What a member of staff may do to an order, and what the API says when they
 * may not.
 *
 * The admin panel decides which buttons to draw from the same rules, but it
 * only decides what is worth showing — every one of these is enforced here, on
 * the server, and these tests are what says so.
 */
class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->branch = Branch::where('code', 'DSM-01')->firstOrFail();
    }

    private function makeOrder(array $attributes = []): Order
    {
        $product = $this->makeProduct($this->branch);

        $order = Order::create(array_merge([
            'company_id' => Company::current()->id,
            'branch_id' => $this->branch->id,
            'order_number' => 'VP' . strtoupper(uniqid()),
            'user_id' => 0,
            'guest_id' => 0,
            'status' => Status::ORDER_PENDING,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal' => 100000,
            'total' => 100000,
            'shipping_address' => ['city' => 'Dar es Salaam'],
        ], $attributes));

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

    /** Strip a permission from a role, as a bespoke role configuration would. */
    private function revokeFromRole(string $role, string $permission): void
    {
        Role::where('name', $role)->where('guard_name', Roles::GUARD)
            ->firstOrFail()
            ->revokePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ------------------------------------------------------ worker statuses

    /**
     * @param  int  $status  One of `config('vipuri.order.worker_allowed_statuses')`
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('workerStatuses')]
    public function test_a_worker_may_move_an_order_along_the_fulfilment_path(int $status): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $order = $this->makeOrder();

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => $status])
            ->assertOk();

        $this->assertSame($status, (int) $order->fresh()->status);
    }

    public static function workerStatuses(): array
    {
        return [
            'processing' => [Status::ORDER_PROCESSING],
            'dispatched' => [Status::ORDER_DISPATCHED],
            'delivered' => [Status::ORDER_DELIVERED],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusesBeyondAWorker')]
    public function test_a_worker_is_refused_any_status_outside_that_path(int $status): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $order = $this->makeOrder();

        $response = $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => $status]);

        $response->assertStatus(403);

        // The panel shows this text verbatim, so it has to say something a
        // worker can act on rather than "Forbidden".
        $this->assertStringContainsString(
            'processing, dispatched or delivered',
            $response->json('message.error.0'),
        );

        $this->assertSame(Status::ORDER_PENDING, (int) $order->fresh()->status);
    }

    public static function statusesBeyondAWorker(): array
    {
        return [
            'cancelled' => [Status::ORDER_CANCELLED],
            'returned' => [Status::ORDER_RETURNED],
            'paid' => [Status::ORDER_PAID],
        ];
    }

    // ------------------------------------------------- cancel / return gate

    /**
     * `order.cancel` was in the permission catalogue and the admin panel hid
     * the button without it, but the route only required `order.update_status`
     * — so posting status 7 directly went through regardless.
     */
    public function test_cancelling_requires_the_cancel_permission(): void
    {
        $this->revokeFromRole(Roles::BRANCH_MANAGER, 'order.cancel');

        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);
        $order = $this->makeOrder();

        $response = $this->withHeaders($this->adminHeaders($manager))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_CANCELLED]);

        $response->assertStatus(403);
        $this->assertStringContainsString('permission to cancel', $response->json('message.error.0'));
        $this->assertSame(Status::ORDER_PENDING, (int) $order->fresh()->status);
    }

    public function test_returning_requires_the_return_permission(): void
    {
        $this->revokeFromRole(Roles::BRANCH_MANAGER, 'order.return');

        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);
        $order = $this->makeOrder(['status' => Status::ORDER_DELIVERED]);

        $response = $this->withHeaders($this->adminHeaders($manager))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_RETURNED]);

        $response->assertStatus(403);
        $this->assertStringContainsString('permission to return', $response->json('message.error.0'));
    }

    public function test_a_manager_holding_the_permission_can_still_cancel(): void
    {
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);
        $order = $this->makeOrder();

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => Status::ORDER_CANCELLED,
                'remark' => 'Customer changed their mind',
            ])
            ->assertOk();

        $fresh = $order->fresh();

        $this->assertSame(Status::ORDER_CANCELLED, (int) $fresh->status);
        $this->assertSame('Customer changed their mind', $fresh->cancel_reason);
    }

    // ------------------------------------------------------------- remarks

    public function test_a_remark_is_kept_in_the_order_history(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $order = $this->makeOrder();

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => Status::ORDER_PROCESSING,
                'remark' => 'Picking from aisle 4',
            ])
            ->assertOk();

        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'to_status' => Status::ORDER_PROCESSING,
            'remark' => 'Picking from aisle 4',
        ]);
    }

    /** The remark is optional — the panel sends null when the box is empty. */
    public function test_a_status_change_without_a_remark_is_accepted(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $order = $this->makeOrder();

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => Status::ORDER_PROCESSING,
                'remark' => null,
            ])
            ->assertOk();

        $this->assertSame(Status::ORDER_PROCESSING, (int) $order->fresh()->status);
    }

    public function test_a_remark_longer_than_the_column_is_rejected_rather_than_truncated(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $order = $this->makeOrder();

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => Status::ORDER_PROCESSING,
                'remark' => str_repeat('a', 256),
            ])
            ->assertStatus(422);

        $this->assertSame(Status::ORDER_PENDING, (int) $order->fresh()->status);
    }

    // ------------------------------------------------------------ mark paid

    public function test_marking_an_order_paid_leaves_its_fulfilment_status_alone(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $order = $this->makeOrder(['status' => Status::ORDER_PROCESSING]);

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/mark-paid")
            ->assertOk();

        $fresh = $order->fresh();

        $this->assertSame(Status::PAYMENT_SUCCESS, (int) $fresh->payment_status);
        $this->assertSame(Status::ORDER_PROCESSING, (int) $fresh->status, 'payment must not move the order along');
    }

    public function test_marking_an_already_paid_order_says_so_instead_of_succeeding_twice(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $order = $this->makeOrder(['payment_status' => Status::PAYMENT_SUCCESS]);

        $response = $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/mark-paid");

        $response->assertStatus(422);
        $this->assertStringContainsString('already marked as paid', $response->json('message.error.0'));
    }

    // -------------------------------------------------------- processed by

    /**
     * `processed_by` is the last staff member to act, and the detail payload
     * has to carry their name for the panel to show it.
     */
    public function test_the_detail_payload_names_the_staff_member_who_last_acted(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch, ['name' => 'Asha Mwinyi']);
        $order = $this->makeOrder();

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_PROCESSING])
            ->assertOk();

        $this->withHeaders($this->adminHeaders($worker))
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.order.processed_by', 'Asha Mwinyi');
    }

    public function test_an_untouched_order_reports_no_processor_rather_than_a_stale_name(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $order = $this->makeOrder();

        $this->withHeaders($this->adminHeaders($admin))
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.order.processed_by', null);
    }

    // ------------------------------------------------------------- search

    public function test_the_list_finds_an_order_by_its_number(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $order = $this->makeOrder(['order_number' => 'VIPURI-2026-000001024']);
        $this->makeOrder(['order_number' => 'VIPURI-2026-000000001']);

        $response = $this->withHeaders($this->adminHeaders($admin))
            ->getJson('/api/v1/admin/orders?search=000001024')
            ->assertOk();

        $this->assertSame([$order->order_number], collect($response->json('data.orders'))->pluck('order_number')->all());
    }

    public function test_the_list_finds_an_order_placed_by_a_signed_in_customer(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $customer = $this->makeCustomer(['firstname' => 'Neema', 'lastname' => 'Kileo']);

        $order = $this->makeOrder(['user_id' => $customer->id]);
        $this->makeOrder();

        $response = $this->withHeaders($this->adminHeaders($admin))
            ->getJson('/api/v1/admin/orders?search=Kileo')
            ->assertOk();

        $this->assertSame([$order->id], collect($response->json('data.orders'))->pluck('id')->all());
    }

    /**
     * A guest checkout has no user row, so searching by name found nothing —
     * a phone order could only ever be located by its order number.
     */
    public function test_the_list_finds_an_order_placed_by_a_guest(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $guest = Guest::create([
            'firstname' => 'Juma',
            'lastname' => 'Rashid',
            'email' => 'juma@example.test',
            'mobile' => '712345678',
        ]);

        $order = $this->makeOrder(['guest_id' => $guest->id]);
        $this->makeOrder();

        $response = $this->withHeaders($this->adminHeaders($admin))
            ->getJson('/api/v1/admin/orders?search=Rashid')
            ->assertOk();

        $this->assertSame([$order->id], collect($response->json('data.orders'))->pluck('id')->all());
    }

    public function test_a_search_that_matches_nothing_returns_an_empty_list_not_everything(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $this->makeOrder();

        $response = $this->withHeaders($this->adminHeaders($admin))
            ->getJson('/api/v1/admin/orders?search=' . urlencode('no-such-customer'))
            ->assertOk();

        $this->assertSame([], $response->json('data.orders'));
    }
}
