<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Constants\Status;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\GeneralSetting;
use App\Models\Order;
use App\Models\OrderCommission;
use App\Models\Product;
use App\Models\StockLog;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Point of sale: counter sales from a branch's own stock.
 */
class PosTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->branch = Branch::where('code', 'DSM-01')->firstOrFail();
        $this->other = Branch::where('code', 'ARU-01')->firstOrFail();
    }

    private function stockAt(Branch $branch, Product $product): int
    {
        return (int) BranchInventory::where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->where('variation_id', 0)
            ->value('stock_quantity');
    }

    private function sell($admin, array $payload)
    {
        return $this->withHeaders($this->adminHeaders($admin))->postJson('/api/v1/admin/pos/sales', $payload);
    }

    private function cashSale(Product $product, int $quantity = 1, array $extra = []): array
    {
        return array_merge([
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'payment_method' => 'cash',
            'amount_received' => 10_000_000,
        ], $extra);
    }

    public function test_a_sales_assistant_sells_from_their_own_branch_and_the_stock_drops(): void
    {
        $seller = $this->makeStaff(Roles::SALES_ASSISTANT, $this->branch);
        $product = $this->makeProduct($this->branch, 10);

        $response = $this->sell($seller, $this->cashSale($product, 3))->assertCreated();

        $order = Order::findOrFail($response->json('data.order.id'));

        $this->assertSame(Order::CHANNEL_POS, $order->channel);
        $this->assertSame($this->branch->id, $order->branch_id);
        $this->assertSame($seller->id, (int) $order->sold_by);
        $this->assertSame($seller->id, (int) $order->processed_by);
        $this->assertSame(Status::ORDER_DELIVERED, (int) $order->status);
        $this->assertSame(Status::PAYMENT_SUCCESS, (int) $order->payment_status);
        $this->assertFalse($order->cod);
        $this->assertSame(0.0, $order->shipping_charge);
        $this->assertSame(300000.0, $order->total);
        $this->assertSame('Walk-in customer', $order->shipping_address->name);

        $this->assertSame(7, $this->stockAt($this->branch, $product));
        $this->assertSame(7, (int) $product->fresh()->stock_quantity, 'catalogue roll-up not synced');

        $log = StockLog::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('sale', $log->remark);
        $this->assertSame(-3, (int) $log->change_quantity);

        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'to_status' => Status::ORDER_DELIVERED,
            'actor_id' => $seller->id,
            'remark' => "Sold at the counter by {$seller->name}",
        ]);

        $response->assertJsonPath('data.receipt.order_number', $order->order_number)
            ->assertJsonPath('data.receipt.seller', $seller->name)
            ->assertJsonPath('data.receipt.branch.id', $this->branch->id);
    }

    public function test_cannot_sell_more_than_is_free_at_the_branch(): void
    {
        $seller = $this->makeStaff(Roles::SALES_ASSISTANT, $this->branch);
        $product = $this->makeProduct($this->branch, 5);

        // Two units are held for an online order: only three are free.
        BranchInventory::where('branch_id', $this->branch->id)->where('product_id', $product->id)
            ->update(['reserved_quantity' => 2]);

        $this->sell($seller, $this->cashSale($product, 4))
            ->assertStatus(422)
            ->assertJsonPath('message.error.0', "Only 3 of \"{$product->name}\" left at this branch");

        $this->assertSame(0, Order::count(), 'a failed sale left an order behind');
        $this->assertSame(5, $this->stockAt($this->branch, $product));

        // Stock elsewhere does not help a branch that has none.
        $elsewhere = $this->makeProduct($this->other, 10);

        $this->sell($seller, $this->cashSale($elsewhere, 1))->assertStatus(422);
    }

    public function test_branch_staff_cannot_sell_from_another_branch(): void
    {
        $seller = $this->makeStaff(Roles::SALES_ASSISTANT, $this->branch);
        $product = $this->makeProduct($this->other, 10);

        $this->sell($seller, $this->cashSale($product, 1, ['branch_id' => $this->other->id]))->assertForbidden();

        $this->assertSame(10, $this->stockAt($this->other, $product));
    }

    public function test_company_wide_staff_must_choose_a_branch_and_may_sell_from_any(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $product = $this->makeProduct($this->other, 10);

        $this->sell($admin, $this->cashSale($product, 1))
            ->assertStatus(422)
            ->assertJsonPath('remark', 'branch_required');

        $this->sell($admin, $this->cashSale($product, 2, ['branch_id' => $this->other->id]))->assertCreated();

        $this->assertSame(8, $this->stockAt($this->other, $product));
    }

    public function test_changing_a_price_or_giving_a_discount_needs_pos_discount(): void
    {
        $assistant = $this->makeStaff(Roles::SALES_ASSISTANT, $this->branch);
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);
        $product = $this->makeProduct($this->branch, 10);

        $this->sell($assistant, [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 80000]],
            'payment_method' => 'card',
        ])->assertForbidden();

        $this->sell($assistant, $this->cashSale($product, 1, ['discount' => 5000]))->assertForbidden();

        // Sending the list price unchanged is not a price change.
        $this->sell($assistant, [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100000]],
            'payment_method' => 'card',
        ])->assertCreated();

        $response = $this->sell($manager, [
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 80000]],
            'discount' => 10000,
            'payment_method' => 'mobile_money',
            'payment_reference' => 'QX12AB34',
        ])->assertCreated();

        $order = Order::findOrFail($response->json('data.order.id'));
        $this->assertSame(160000.0, $order->subtotal);
        $this->assertSame(10000.0, $order->discount);
        $this->assertSame(150000.0, $order->total);
        $this->assertSame('mobile_money', $order->payment_method);
        $this->assertSame('QX12AB34', $order->payment_reference);
        $this->assertSame(0.0, $order->change_due);
    }

    public function test_cash_change_is_computed_and_short_cash_is_refused(): void
    {
        $seller = $this->makeStaff(Roles::SALES_ASSISTANT, $this->branch);
        $product = $this->makeProduct($this->branch, 10);

        $this->sell($seller, $this->cashSale($product, 1, ['amount_received' => 90000]))
            ->assertStatus(422)
            ->assertJsonPath('message.error.0', 'The cash received is less than the amount due');

        $this->sell($seller, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method' => 'cash'])
            ->assertStatus(422);

        $response = $this->sell($seller, $this->cashSale($product, 1, ['amount_received' => 120000]))->assertCreated();

        $response->assertJsonPath('data.receipt.payment.amount_received', 120000)
            ->assertJsonPath('data.receipt.payment.change_due', 20000)
            ->assertJsonPath('data.receipt.payment.label', 'Cash');
    }

    public function test_tax_is_charged_as_online(): void
    {
        $tax = Tax::create(['name' => 'VAT', 'rate' => 18, 'status' => 1]);
        $seller = $this->makeStaff(Roles::SALES_ASSISTANT, $this->branch);
        $product = $this->makeProduct($this->branch, 10, ['tax_class' => $tax->id, 'tax_status' => 'taxable']);

        $response = $this->sell($seller, $this->cashSale($product, 2))->assertCreated();
        $order = Order::findOrFail($response->json('data.order.id'));

        // 100,000 + 18% VAT, twice — the cart's rule.
        $this->assertSame(236000.0, $order->subtotal);
        $this->assertSame(36000.0, $order->total_tax);
        $this->assertSame(236000.0, $order->total);
        $response->assertJsonPath('data.receipt.totals.net', 200000);
    }

    public function test_counter_sales_appear_in_the_orders_list_with_their_channel(): void
    {
        $seller = $this->makeStaff(Roles::SALES_ASSISTANT, $this->branch);
        $product = $this->makeProduct($this->branch, 10);

        $this->sell($seller, $this->cashSale($product, 1, ['customer' => ['name' => 'Juma Hassan', 'mobile' => '0754000111']]))
            ->assertCreated();

        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);

        $this->withHeaders($this->adminHeaders($manager))
            ->getJson('/api/v1/admin/orders?channel=pos')
            ->assertOk()
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.channel', 'pos')
            ->assertJsonPath('data.orders.0.channel_label', 'Counter sale')
            ->assertJsonPath('data.orders.0.sold_by', $seller->name)
            ->assertJsonPath('data.orders.0.payment_method', 'cash')
            ->assertJsonPath('data.orders.0.customer_name', 'Juma Hassan');

        $this->withHeaders($this->adminHeaders($manager))
            ->getJson('/api/v1/admin/orders?channel=online')
            ->assertOk()
            ->assertJsonCount(0, 'data.orders');

        $this->withHeaders($this->adminHeaders($manager))
            ->getJson('/api/v1/admin/orders?search=0754000111')
            ->assertOk()
            ->assertJsonCount(1, 'data.orders');

        $sales = $this->withHeaders($this->adminHeaders($seller))
            ->getJson('/api/v1/admin/pos/sales')
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.revenue', 100000)
            ->assertJsonPath('data.summary.by_payment_method.cash.count', 1);

        $id = $sales->json('data.sales.0.id');

        $this->withHeaders($this->adminHeaders($seller))
            ->getJson("/api/v1/admin/pos/sales/{$id}")
            ->assertOk()
            ->assertJsonPath('data.receipt.customer.name', 'Juma Hassan');

        // Another branch's staff cannot read the receipt.
        $stranger = $this->makeStaff(Roles::SALES_ASSISTANT, $this->other);

        $this->withHeaders($this->adminHeaders($stranger))
            ->getJson("/api/v1/admin/pos/sales/{$id}")
            ->assertForbidden();

        $this->withHeaders($this->adminHeaders($stranger))
            ->getJson('/api/v1/admin/pos/sales')
            ->assertOk()
            ->assertJsonCount(0, 'data.sales');
    }

    public function test_a_sale_can_be_for_a_registered_customer(): void
    {
        $seller = $this->makeStaff(Roles::SALES_ASSISTANT, $this->branch);
        $customer = $this->makeCustomer(['firstname' => 'Neema', 'lastname' => 'Mushi']);
        $product = $this->makeProduct($this->branch, 10);

        $this->withHeaders($this->adminHeaders($seller))
            ->getJson('/api/v1/admin/pos/customers?search=Neema')
            ->assertOk()
            ->assertJsonPath('data.customers.0.id', $customer->id);

        $response = $this->sell($seller, $this->cashSale($product, 1, ['customer' => ['user_id' => $customer->id]]))
            ->assertCreated();

        $this->assertSame($customer->id, (int) Order::find($response->json('data.order.id'))->user_id);
    }

    public function test_product_search_shows_branch_stock_and_hides_unsellable_types(): void
    {
        $seller = $this->makeStaff(Roles::SALES_ASSISTANT, $this->branch);
        $product = $this->makeProduct($this->branch, 6, ['name' => 'Brake Pad Front', 'sku' => 'BP-100', 'sale_price' => 90000]);
        BranchInventory::where('product_id', $product->id)->update(['reserved_quantity' => 1]);
        $this->makeProduct($this->branch, 6, ['name' => 'Brake Kit Bundle', 'product_type' => 'grouped']);

        $this->withHeaders($this->adminHeaders($seller))
            ->getJson('/api/v1/admin/pos/products?search=Brake')
            ->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.id', $product->id)
            ->assertJsonPath('data.products.0.available', 5)
            ->assertJsonPath('data.products.0.price', 90000)
            ->assertJsonPath('data.context.branch.id', $this->branch->id)
            ->assertJsonPath('data.context.company_wide', false)
            ->assertJsonPath('data.context.can_discount', false);

        $this->withHeaders($this->adminHeaders($seller))
            ->getJson('/api/v1/admin/pos/products?search=BP-100')
            ->assertOk()
            ->assertJsonCount(1, 'data.products');
    }

    public function test_staff_without_pos_sell_are_refused(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);
        $product = $this->makeProduct($this->branch, 10);

        $this->sell($worker, $this->cashSale($product))->assertForbidden();
        $this->withHeaders($this->adminHeaders($worker))->getJson('/api/v1/admin/pos/products')->assertForbidden();
    }

    public function test_the_seller_earns_commission_when_the_scheme_is_on(): void
    {
        GeneralSetting::query()->update(['commission_enabled' => 1, 'commission_rate' => 2, 'commission_attribution' => 'processed_by']);
        Cache::forget('GeneralSetting');
        (function () {
            static::$instance = null;
        })->call(new GeneralSetting);

        $seller = $this->makeStaff(Roles::SALES_ASSISTANT, $this->branch);
        $product = $this->makeProduct($this->branch, 10);

        $response = $this->sell($seller, $this->cashSale($product, 2))->assertCreated();

        $commission = OrderCommission::where('order_id', $response->json('data.order.id'))->firstOrFail();

        // Credited to the seller even under the "processed by" rule: a counter
        // sale is taken on and completed by the same person.
        $this->assertSame($seller->id, (int) $commission->admin_id);
        $this->assertSame(200000.0, (float) $commission->basis_amount);
        $this->assertSame(4000.0, (float) $commission->amount);
    }

    public function test_returning_a_counter_sale_restocks_the_branch_and_reverses_commission(): void
    {
        GeneralSetting::query()->update(['commission_enabled' => 1, 'commission_rate' => 2]);
        Cache::forget('GeneralSetting');
        (function () {
            static::$instance = null;
        })->call(new GeneralSetting);

        $seller = $this->makeStaff(Roles::SALES_ASSISTANT, $this->branch);
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->branch);
        $product = $this->makeProduct($this->branch, 10);

        $orderId = $this->sell($seller, $this->cashSale($product, 4))->assertCreated()->json('data.order.id');
        $this->assertSame(6, $this->stockAt($this->branch, $product));

        $this->withHeaders($this->adminHeaders($manager))
            ->postJson("/api/v1/admin/orders/{$orderId}/status", ['status' => Status::ORDER_RETURNED, 'remark' => 'Wrong part'])
            ->assertOk();

        $this->assertSame(10, $this->stockAt($this->branch, $product));
        $this->assertSame(Status::ORDER_RETURNED, (int) Order::find($orderId)->status);
        $this->assertSame(Status::COMMISSION_REVERSED, (int) OrderCommission::where('order_id', $orderId)->value('status'));

        // A returned sale no longer counts in the day's takings.
        $this->withHeaders($this->adminHeaders($seller))
            ->getJson('/api/v1/admin/pos/sales')
            ->assertJsonPath('data.summary.count', 0);
    }
}
