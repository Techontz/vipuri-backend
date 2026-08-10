<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Constants\Status;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock movement across the order lifecycle and between branches.
 */
class InventoryWorkflowTest extends TestCase
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

    private function makeOrderFor(Product $product, Branch $branch, int $quantity = 2): Order
    {
        $order = Order::create([
            'company_id' => Company::current()->id,
            'branch_id' => $branch->id,
            'order_number' => 'VP' . strtoupper(uniqid()),
            'user_id' => 0,
            'guest_id' => 0,
            'status' => Status::ORDER_PENDING,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal' => 100000 * $quantity,
            'total' => 100000 * $quantity,
            'shipping_address' => ['city' => 'Dar es Salaam'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variation_id' => 0,
            'quantity' => $quantity,
            'price' => 100000,
            'subtotal' => 100000 * $quantity,
        ]);

        $order->load('orderItems');
        app(InventoryService::class)->reserve($order);

        return $order;
    }

    private function stockRow(Product $product, Branch $branch)
    {
        return $product->branchInventories()->where('branch_id', $branch->id)->where('variation_id', 0)->first();
    }

    public function test_stock_adjustment_writes_a_log_and_updates_the_catalogue_total(): void
    {
        $product = $this->makeProduct($this->branchA, 10);
        $super = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($super))
            ->postJson('/api/v1/admin/inventory/adjust', [
                'branch_id' => $this->branchA->id,
                'product_id' => $product->id,
                'variation_id' => 0,
                'mode' => 'delta',
                'quantity' => 5,
                'description' => 'Restock from supplier',
            ])
            ->assertOk();

        $this->assertSame(15, (int) $this->stockRow($product, $this->branchA)->stock_quantity);
        $this->assertSame(15, (int) $product->fresh()->stock_quantity);

        $this->assertDatabaseHas('stock_logs', [
            'product_id' => $product->id,
            'branch_id' => $this->branchA->id,
            'change_quantity' => 5,
            'post_quantity' => 15,
        ]);
    }

    public function test_an_absolute_stock_count_sets_the_exact_quantity(): void
    {
        $product = $this->makeProduct($this->branchA, 10);
        $super = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($super))
            ->postJson('/api/v1/admin/inventory/adjust', [
                'branch_id' => $this->branchA->id,
                'product_id' => $product->id,
                'mode' => 'absolute',
                'quantity' => 3,
                'description' => 'Physical count',
            ])
            ->assertOk();

        $this->assertSame(3, (int) $this->stockRow($product, $this->branchA)->stock_quantity);
    }

    public function test_stock_is_deducted_when_an_order_is_dispatched(): void
    {
        $product = $this->makeProduct($this->branchA, 10);
        $order = $this->makeOrderFor($product, $this->branchA, 2);
        $super = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->assertSame(2, (int) $this->stockRow($product, $this->branchA)->reserved_quantity);

        $this->withHeaders($this->adminHeaders($super))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_DISPATCHED])
            ->assertOk();

        $row = $this->stockRow($product, $this->branchA);

        $this->assertSame(8, (int) $row->stock_quantity);
        $this->assertSame(0, (int) $row->reserved_quantity);
        $this->assertSame(8, (int) $product->fresh()->stock_quantity);
    }

    public function test_cancelling_a_dispatched_order_returns_the_stock(): void
    {
        $product = $this->makeProduct($this->branchA, 10);
        $order = $this->makeOrderFor($product, $this->branchA, 2);
        $super = $this->makeStaff(Roles::SUPER_ADMIN);
        $headers = $this->adminHeaders($super);

        $this->withHeaders($headers)->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_DISPATCHED])->assertOk();
        $this->assertSame(8, (int) $this->stockRow($product, $this->branchA)->stock_quantity);

        $this->withHeaders($headers)
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_CANCELLED, 'remark' => 'Customer changed mind'])
            ->assertOk();

        $this->assertSame(10, (int) $this->stockRow($product, $this->branchA)->stock_quantity);
    }

    public function test_a_returned_order_restocks_the_branch(): void
    {
        $product = $this->makeProduct($this->branchA, 10);
        $order = $this->makeOrderFor($product, $this->branchA, 3);
        $super = $this->makeStaff(Roles::SUPER_ADMIN);
        $headers = $this->adminHeaders($super);

        $this->withHeaders($headers)->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_DISPATCHED])->assertOk();
        $this->withHeaders($headers)->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_DELIVERED])->assertOk();
        $this->withHeaders($headers)->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_RETURNED])->assertOk();

        $this->assertSame(10, (int) $this->stockRow($product, $this->branchA)->stock_quantity);
    }

    public function test_delivering_a_cash_on_delivery_order_marks_it_paid(): void
    {
        $product = $this->makeProduct($this->branchA, 10);
        $order = $this->makeOrderFor($product, $this->branchA, 1);
        $order->update(['cod' => true]);

        $super = $this->makeStaff(Roles::SUPER_ADMIN);
        $headers = $this->adminHeaders($super);

        $this->withHeaders($headers)->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_DISPATCHED])->assertOk();
        $this->withHeaders($headers)->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_DELIVERED])->assertOk();

        $this->assertSame(Status::PAYMENT_SUCCESS, $order->fresh()->payment_status);
    }

    public function test_every_status_change_is_recorded(): void
    {
        $product = $this->makeProduct($this->branchA, 10);
        $order = $this->makeOrderFor($product, $this->branchA, 1);
        $super = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($super))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_PROCESSING])
            ->assertOk();

        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'to_status' => Status::ORDER_PROCESSING,
            'actor_type' => 'admin',
        ]);

        $this->assertDatabaseHas('audit_logs', ['event' => 'order.status_changed']);
    }

    public function test_a_branch_worker_may_only_use_fulfilment_statuses(): void
    {
        $product = $this->makeProduct($this->branchA, 10);
        $order = $this->makeOrderFor($product, $this->branchA, 1);
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branchA);

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_PROCESSING])
            ->assertOk();

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => Status::ORDER_CANCELLED])
            ->assertStatus(403);

        $this->assertSame(Status::ORDER_PROCESSING, $order->fresh()->status);
    }

    public function test_a_stock_transfer_moves_units_between_branches(): void
    {
        $product = $this->makeProduct($this->branchA, 10);
        // Give the destination an inventory row so the assertion is unambiguous.
        app(InventoryService::class)->row($this->branchB->id, $product->id, 0);

        $super = $this->makeStaff(Roles::SUPER_ADMIN);
        $headers = $this->adminHeaders($super);

        $transfer = $this->withHeaders($headers)
            ->postJson('/api/v1/admin/inventory/transfers', [
                'from_branch_id' => $this->branchA->id,
                'to_branch_id' => $this->branchB->id,
                'note' => 'Rebalancing',
                'items' => [['product_id' => $product->id, 'variation_id' => 0, 'quantity' => 4]],
            ])
            ->assertOk()
            ->json('data.transfer.id');

        $this->withHeaders($headers)->postJson("/api/v1/admin/inventory/transfers/{$transfer}/dispatch")->assertOk();

        $this->assertSame(6, (int) $this->stockRow($product, $this->branchA)->stock_quantity);

        $this->withHeaders($headers)->postJson("/api/v1/admin/inventory/transfers/{$transfer}/receive")->assertOk();

        $this->assertSame(4, (int) $this->stockRow($product, $this->branchB)->stock_quantity);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
    }

    public function test_a_transfer_cannot_dispatch_more_than_the_source_holds(): void
    {
        $product = $this->makeProduct($this->branchA, 2);
        $super = $this->makeStaff(Roles::SUPER_ADMIN);
        $headers = $this->adminHeaders($super);

        $transfer = $this->withHeaders($headers)
            ->postJson('/api/v1/admin/inventory/transfers', [
                'from_branch_id' => $this->branchA->id,
                'to_branch_id' => $this->branchB->id,
                'items' => [['product_id' => $product->id, 'variation_id' => 0, 'quantity' => 50]],
            ])
            ->assertOk()
            ->json('data.transfer.id');

        $this->withHeaders($headers)
            ->postJson("/api/v1/admin/inventory/transfers/{$transfer}/dispatch")
            ->assertStatus(422);

        $this->assertSame(2, (int) $this->stockRow($product, $this->branchA)->stock_quantity);
    }

    public function test_a_partially_received_transfer_returns_the_shortfall(): void
    {
        $product = $this->makeProduct($this->branchA, 10);
        $super = $this->makeStaff(Roles::SUPER_ADMIN);
        $headers = $this->adminHeaders($super);

        $created = $this->withHeaders($headers)
            ->postJson('/api/v1/admin/inventory/transfers', [
                'from_branch_id' => $this->branchA->id,
                'to_branch_id' => $this->branchB->id,
                'items' => [['product_id' => $product->id, 'variation_id' => 0, 'quantity' => 6]],
            ])
            ->assertOk();

        $transferId = $created->json('data.transfer.id');
        $itemId = $created->json('data.transfer.items.0.id');

        $this->withHeaders($headers)->postJson("/api/v1/admin/inventory/transfers/{$transferId}/dispatch")->assertOk();
        $this->withHeaders($headers)
            ->postJson("/api/v1/admin/inventory/transfers/{$transferId}/receive", ['received' => [$itemId => 4]])
            ->assertOk();

        $this->assertSame(4, (int) $this->stockRow($product, $this->branchB)->stock_quantity);
        // 10 - 6 dispatched + 2 shortfall returned.
        $this->assertSame(6, (int) $this->stockRow($product, $this->branchA)->stock_quantity);
    }

    public function test_low_stock_rows_surface_in_the_inventory_listing(): void
    {
        $product = $this->makeProduct($this->branchA, 1);
        $product->branchInventories()->where('branch_id', $this->branchA->id)->update(['min_stock_quantity' => 5]);

        $super = $this->makeStaff(Roles::SUPER_ADMIN);

        $response = $this->withHeaders($this->adminHeaders($super))
            ->getJson('/api/v1/admin/inventory?low_stock=1')
            ->assertOk();

        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertTrue($response->json('data.inventory.0.is_low'));
    }
}
