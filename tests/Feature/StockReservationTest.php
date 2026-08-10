<?php

namespace Tests\Feature;

use App\Constants\Status;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Stock is checked when an item goes into the cart, but a cart can sit open for
 * hours and two shoppers can reach checkout at the same moment. These cover the
 * re-check that happens when the hold is actually taken.
 */
class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Product $product;

    private ShippingRate $rate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->branch = Branch::where('code', 'DSM-01')->firstOrFail();
        $this->product = $this->makeProduct($this->branch, 1);

        $zone = ShippingZone::create(['name' => 'Dar es Salaam', 'status' => 1]);
        $method = ShippingMethod::create(['name' => 'Standard Delivery', 'status' => 1]);

        $this->rate = ShippingRate::create([
            'shipping_zone_id' => $zone->id,
            'shipping_method_id' => $method->id,
            'amount' => 5000,
            'min_order_amount' => 0,
            'max_order_amount' => 0,
            'expected_delivery_days' => 2,
            'is_cod' => 1,
            'status' => 1,
        ]);
    }

    private function inventoryRow(): BranchInventory
    {
        return BranchInventory::where('branch_id', $this->branch->id)
            ->where('product_id', $this->product->id)
            ->where('variation_id', 0)
            ->firstOrFail();
    }

    /** Fill a cart under the given token and take it to the point of ordering. */
    private function readyCart(string $token, int $quantity = 1): array
    {
        $headers = $this->cartHeaders($token);

        $this->withHeaders($headers)
            ->postJson("/api/v1/cart/add/{$this->product->slug}", ['quantity' => $quantity])
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/v1/cart/shipping-rate', ['shipping_rate_id' => $this->rate->id])
            ->assertOk();

        return $headers;
    }

    private function shippingPayload(): array
    {
        return [
            'firstname' => 'Asha',
            'lastname' => 'Mwinyi',
            'email' => 'asha@example.co.tz',
            'dial_code' => '+255',
            'mobile' => '754111001',
            'address' => 'Msimbazi Street',
            'city' => 'Dar es Salaam',
            'country_name' => 'Tanzania',
            'country_code' => 'TZ',
        ];
    }

    /**
     * Two shoppers hold the same last unit in their carts. Whoever checks out
     * second must be turned away rather than both orders being accepted.
     */
    public function test_the_last_unit_cannot_be_sold_twice(): void
    {
        $first = $this->readyCart('cart-shopper-one');
        $second = $this->readyCart('cart-shopper-two');

        $this->withHeaders($first)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->assertOk();

        $this->withHeaders($second)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->assertStatus(422)
            ->assertJsonPath('remark', 'error');

        $this->assertSame(1, Order::count(), 'Only the first checkout may produce an order');

        $row = $this->inventoryRow();
        $this->assertSame(1, (int) $row->stock_quantity);
        $this->assertSame(1, (int) $row->reserved_quantity, 'The unit is held exactly once');
    }

    /**
     * A cart filled while stock was on the shelf, checked out after a stock take
     * emptied it.
     */
    public function test_a_cart_held_past_a_stock_out_is_refused_at_checkout(): void
    {
        $headers = $this->readyCart('cart-slow-shopper');

        app(InventoryService::class)->setQuantity(
            $this->branch->id,
            $this->product->id,
            0,
            0,
            'Stock take found none',
        );

        $this->withHeaders($headers)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, (int) $this->inventoryRow()->reserved_quantity);
    }

    /** A backorder product is exempt, as it is when added to the cart. */
    public function test_a_backorder_product_can_still_be_ordered_with_no_stock(): void
    {
        $this->product->update(['allow_backorder' => 1]);

        app(InventoryService::class)->setQuantity($this->branch->id, $this->product->id, 0, 0, 'Emptied');

        $headers = $this->readyCart('cart-backorder');

        $this->withHeaders($headers)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->assertOk();

        $this->assertSame(1, Order::count());
    }

    /**
     * Reservations are a read-modify-write on one row. Applied back to back they
     * must accumulate — a lost update here silently releases somebody's hold.
     */
    public function test_reservations_accumulate_rather_than_overwrite(): void
    {
        app(InventoryService::class)->setQuantity($this->branch->id, $this->product->id, 0, 10, 'Restocked');

        $inventory = app(InventoryService::class);

        foreach (range(1, 3) as $n) {
            $order = Order::create([
                'company_id' => Company::current()->id,
                'branch_id' => $this->branch->id,
                'order_number' => "VP-RES-$n",
                'user_id' => 0,
                'guest_id' => 0,
                'status' => Status::ORDER_PENDING,
                'payment_status' => Status::PAYMENT_INITIATE,
                'subtotal' => 100000,
                'total' => 100000,
                'shipping_address' => ['name' => 'Asha'],
            ]);

            $order->orderItems()->create([
                'product_id' => $this->product->id,
                'variation_id' => 0,
                'product_name' => $this->product->name,
                'quantity' => 2,
                'price' => 100000,
                'subtotal' => 200000,
            ]);

            $inventory->reserve($order->load('orderItems'));
        }

        $this->assertSame(6, (int) $this->inventoryRow()->reserved_quantity);
        $this->assertSame(4, $inventory->sellableQuantity($this->product->id));
    }

    /** Reserving beyond what is free is refused outright. */
    public function test_reserving_more_than_is_free_is_refused(): void
    {
        $order = Order::create([
            'company_id' => Company::current()->id,
            'branch_id' => $this->branch->id,
            'order_number' => 'VP-RES-OVER',
            'user_id' => 0,
            'guest_id' => 0,
            'status' => Status::ORDER_PENDING,
            'payment_status' => Status::PAYMENT_INITIATE,
            'subtotal' => 100000,
            'total' => 100000,
            'shipping_address' => ['name' => 'Asha'],
        ]);

        $order->orderItems()->create([
            'product_id' => $this->product->id,
            'variation_id' => 0,
            'product_name' => $this->product->name,
            'quantity' => 5,
            'price' => 100000,
            'subtotal' => 500000,
        ]);

        $this->expectException(RuntimeException::class);

        app(InventoryService::class)->reserve($order->load('orderItems'));
    }

    /** Cancelling gives the unit straight back to the next shopper. */
    public function test_the_unit_is_sellable_again_once_the_first_order_is_cancelled(): void
    {
        $first = $this->readyCart('cart-shopper-one');

        $this->withHeaders($first)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->assertOk();

        $order = Order::firstOrFail();

        app(\App\Services\OrderService::class)
            ->changeStatus($order->load('orderItems'), Status::ORDER_CANCELLED, 'Changed my mind');

        $this->assertSame(0, (int) $this->inventoryRow()->reserved_quantity);

        $second = $this->readyCart('cart-shopper-two');

        $this->withHeaders($second)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->assertOk();

        $this->assertSame(2, Order::count());
    }
}
