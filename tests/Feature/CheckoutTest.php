<?php

namespace Tests\Feature;

use App\Constants\Status;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Product $product;

    private ShippingRate $rate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->seedGateways();

        $this->branch = Branch::where('code', 'DSM-01')->firstOrFail();
        $this->product = $this->makeProduct($this->branch, 10);

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

    private function addToCart(array $headers, int $quantity = 1): void
    {
        $this->withHeaders($headers)
            ->postJson("/api/v1/cart/add/{$this->product->slug}", ['quantity' => $quantity])
            ->assertOk();
    }

    private function chooseRate(array $headers): void
    {
        $this->withHeaders($headers)
            ->postJson('/api/v1/cart/shipping-rate', ['shipping_rate_id' => $this->rate->id])
            ->assertOk();
    }

    private function shippingPayload(array $overrides = []): array
    {
        return array_merge([
            'firstname' => 'Asha',
            'lastname' => 'Mwinyi',
            'email' => 'asha@example.co.tz',
            'dial_code' => '+255',
            'mobile' => '754111001',
            'address' => 'Msimbazi Street',
            'city' => 'Dar es Salaam',
            'country_name' => 'Tanzania',
            'country_code' => 'TZ',
        ], $overrides);
    }

    public function test_a_guest_can_add_a_product_to_the_cart(): void
    {
        $headers = $this->cartHeaders('guest-1');

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/cart/add/{$this->product->slug}", ['quantity' => 2])
            ->assertOk();

        $this->assertSame(1, $response->json('data.cart_count'));
        $this->assertSame(200000.0, (float) $response->json('data.summary.subtotal'));
    }

    public function test_a_cart_is_private_to_its_token(): void
    {
        $this->addToCart($this->cartHeaders('guest-a'));

        $other = $this->withHeaders($this->cartHeaders('guest-b'))->getJson('/api/v1/cart')->assertOk();

        $this->assertCount(0, $other->json('data.items'));
    }

    public function test_stock_limits_are_enforced_when_adding_to_the_cart(): void
    {
        $this->withHeaders($this->cartHeaders('guest-2'))
            ->postJson("/api/v1/cart/add/{$this->product->slug}", ['quantity' => 999])
            ->assertStatus(422);
    }

    public function test_cart_quantity_can_be_updated_and_the_item_removed(): void
    {
        $headers = $this->cartHeaders('guest-3');
        $this->addToCart($headers);

        $itemId = $this->withHeaders($headers)->getJson('/api/v1/cart')->json('data.items.0.id');

        $updated = $this->withHeaders($headers)
            ->postJson('/api/v1/cart/update', ['id' => $itemId, 'quantity' => 3])
            ->assertOk();

        $this->assertSame(3, $updated->json('data.items.0.quantity'));

        $removed = $this->withHeaders($headers)
            ->postJson('/api/v1/cart/remove', ['id' => $itemId])
            ->assertOk();

        $this->assertSame(0, $removed->json('data.cart_count'));
    }

    public function test_a_percentage_coupon_reduces_the_payable_total(): void
    {
        Coupon::create([
            'code' => 'TEST10',
            'name' => 'Ten percent',
            'discount_type' => Status::COUPON_DISCOUNT_PERCENT,
            'amount' => 10,
            'status' => 1,
        ]);

        $headers = $this->cartHeaders('guest-4');
        $this->addToCart($headers);

        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/cart/coupon', ['code' => 'TEST10'])
            ->assertOk();

        $this->assertSame(10000.0, (float) $response->json('data.summary.discount'));
        $this->assertSame(90000.0, (float) $response->json('data.summary.payable'));
    }

    public function test_an_invalid_coupon_is_rejected(): void
    {
        $headers = $this->cartHeaders('guest-5');
        $this->addToCart($headers);

        $this->withHeaders($headers)
            ->postJson('/api/v1/cart/coupon', ['code' => 'NOPE'])
            ->assertStatus(422);
    }

    public function test_a_coupon_is_dropped_when_the_cart_no_longer_qualifies(): void
    {
        Coupon::create([
            'code' => 'BIGSPEND',
            'discount_type' => Status::COUPON_DISCOUNT_PERCENT,
            'amount' => 10,
            'minimum_spend' => 150000,
            'status' => 1,
        ]);

        $headers = $this->cartHeaders('guest-6');
        $this->addToCart($headers, 2); // 200,000 — qualifies

        $this->withHeaders($headers)->postJson('/api/v1/cart/coupon', ['code' => 'BIGSPEND'])->assertOk();

        $itemId = $this->withHeaders($headers)->getJson('/api/v1/cart')->json('data.items.0.id');

        // Drop to one unit (100,000) — below the minimum spend.
        $updated = $this->withHeaders($headers)
            ->postJson('/api/v1/cart/update', ['id' => $itemId, 'quantity' => 1])
            ->assertOk();

        $this->assertSame(0.0, (float) $updated->json('data.summary.discount'));
        $this->assertNull($updated->json('data.summary.coupon'));
    }

    public function test_checkout_requires_a_delivery_option(): void
    {
        $headers = $this->cartHeaders('guest-7');
        $this->addToCart($headers);

        $this->withHeaders($headers)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->assertStatus(422);
    }

    public function test_a_guest_can_place_an_order_and_stock_is_reserved(): void
    {
        $headers = $this->cartHeaders('guest-8');
        $this->addToCart($headers, 2);
        $this->chooseRate($headers);

        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->assertOk();

        $orderNumber = $response->json('data.order.order_number');

        $this->assertNotEmpty($orderNumber);
        $this->assertSame(205000.0, (float) $response->json('data.order.total'));
        $this->assertSame($this->branch->id, $response->json('data.order.branch.id'));

        // Stock is held, not yet deducted.
        $inventory = $this->product->branchInventories()->where('branch_id', $this->branch->id)->first();
        $this->assertSame(10, (int) $inventory->stock_quantity);
        $this->assertSame(2, (int) $inventory->reserved_quantity);

        // The cart is emptied.
        $this->assertCount(0, $this->withHeaders($headers)->getJson('/api/v1/cart')->json('data.items'));
    }

    public function test_the_order_is_readable_by_its_own_cart_token_only(): void
    {
        $headers = $this->cartHeaders('guest-9');
        $this->addToCart($headers);
        $this->chooseRate($headers);

        $orderNumber = $this->withHeaders($headers)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->json('data.order.order_number');

        $this->withHeaders($headers)
            ->getJson("/api/v1/orders/{$orderNumber}/confirmation")
            ->assertOk();

        $this->withHeaders($this->cartHeaders('someone-else'))
            ->getJson("/api/v1/orders/{$orderNumber}/confirmation")
            ->assertStatus(403);
    }

    public function test_a_manual_payment_can_be_started_and_submitted(): void
    {
        $headers = $this->cartHeaders('guest-10');
        $this->addToCart($headers);
        $this->chooseRate($headers);

        $orderNumber = $this->withHeaders($headers)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->json('data.order.order_number');

        $methods = $this->withHeaders($headers)
            ->getJson("/api/v1/checkout/{$orderNumber}/payment-methods")
            ->assertOk()
            ->json('data.methods');

        $this->assertNotEmpty($methods, 'At least one manual gateway should be offered');

        $pay = $this->withHeaders($headers)
            ->postJson("/api/v1/checkout/{$orderNumber}/pay", ['gateway_currency_id' => $methods[0]['id']])
            ->assertOk();

        $trx = $pay->json('data.trx');

        $this->withHeaders($headers)
            ->postJson("/api/v1/payment/manual/{$trx}", [
                'detail' => ['transaction_code' => 'QWE123', 'paying_number' => '0754111001'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('deposits', ['trx' => $trx, 'status' => Status::PAYMENT_PENDING]);
        $this->assertDatabaseHas('orders', ['order_number' => $orderNumber, 'payment_status' => Status::PAYMENT_PENDING]);
    }

    private function placeOrder(array $headers): string
    {
        $this->addToCart($headers);
        $this->chooseRate($headers);

        return $this->withHeaders($headers)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->json('data.order.order_number');
    }

    public function test_mobile_money_needs_only_a_phone_number_and_detects_the_network(): void
    {
        $headers = $this->cartHeaders('guest-mm-1');
        $orderNumber = $this->placeOrder($headers);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/checkout/{$orderNumber}/mobile-money", ['phone' => '0688 123 456'])
            ->assertOk()
            ->assertJsonPath('data.network', 'Airtel Money')
            ->assertJsonPath('data.phone', '+255 688 123 456')
            ->assertJsonPath('data.push_sent', false);

        $this->assertDatabaseHas('deposits', [
            'trx' => $response->json('data.trx'),
            'method_code' => 1003,
            'status' => Status::PAYMENT_PENDING,
        ]);
        $this->assertDatabaseHas('orders', ['order_number' => $orderNumber, 'payment_status' => Status::PAYMENT_PENDING]);
    }

    public function test_mobile_money_accepts_every_common_number_format(): void
    {
        foreach (['0754111001', '754111001', '+255 754 111 001', '255-754-111-001'] as $input) {
            $this->assertSame('255754111001', \App\Support\MobileMoney::normalise($input), $input);
        }

        $this->assertNull(\App\Support\MobileMoney::normalise('0222861000'), 'A landline is not a mobile number');
        $this->assertNull(\App\Support\MobileMoney::normalise('07541'));
        $this->assertSame('vodacom', \App\Support\MobileMoney::network('255754111001')['key']);
        $this->assertSame('tigo', \App\Support\MobileMoney::network('255714111001')['key']);
    }

    public function test_mobile_money_rejects_an_invalid_number_and_a_second_request(): void
    {
        $headers = $this->cartHeaders('guest-mm-2');
        $orderNumber = $this->placeOrder($headers);

        $this->withHeaders($headers)
            ->postJson("/api/v1/checkout/{$orderNumber}/mobile-money", ['phone' => '12345'])
            ->assertJsonPath('remark', 'invalid_phone');

        $this->withHeaders($headers)
            ->postJson("/api/v1/checkout/{$orderNumber}/mobile-money", ['phone' => '0754111001'])
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/api/v1/checkout/{$orderNumber}/mobile-money", ['phone' => '0754111001'])
            ->assertJsonPath('remark', 'payment_pending');

        $this->assertSame(1, \App\Models\Deposit::count());
    }

    public function test_mobile_money_is_private_to_the_order_owner(): void
    {
        $orderNumber = $this->placeOrder($this->cartHeaders('guest-mm-3'));

        $this->withHeaders($this->cartHeaders('someone-else'))
            ->postJson("/api/v1/checkout/{$orderNumber}/mobile-money", ['phone' => '0754111001'])
            ->assertForbidden();
    }

    public function test_cash_on_delivery_skips_the_payment_step(): void
    {
        $headers = $this->cartHeaders('guest-11');
        $this->addToCart($headers);
        $this->chooseRate($headers);

        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/checkout', $this->shippingPayload(['cod' => true]))
            ->assertOk();

        $this->assertFalse($response->json('data.requires_payment'));
        $this->assertDatabaseHas('orders', [
            'order_number' => $response->json('data.order.order_number'),
            'cod' => 1,
        ]);
    }

    public function test_a_signed_in_customer_sees_the_order_in_their_history(): void
    {
        $customer = $this->makeCustomer();
        $headers = array_merge($this->userHeaders($customer), $this->cartHeaders('user-cart'));

        $this->addToCart($headers);
        $this->chooseRate($headers);

        $orderNumber = $this->withHeaders($headers)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->json('data.order.order_number');

        $orders = $this->withHeaders($this->userHeaders($customer))
            ->getJson('/api/v1/user/orders')
            ->assertOk()
            ->json('data.orders');

        $this->assertSame($orderNumber, $orders[0]['order_number']);
    }

    public function test_a_guest_cart_merges_into_the_account_on_login(): void
    {
        $customer = $this->makeCustomer(['username' => 'merge.user']);
        $guest = $this->cartHeaders('merge-cart');

        $this->addToCart($guest, 2);

        $this->withHeaders($guest)
            ->postJson('/api/v1/auth/login', ['username' => 'merge.user', 'password' => 'Testing@2026'])
            ->assertOk();

        $cart = $this->withHeaders($this->userHeaders($customer))->getJson('/api/v1/cart')->assertOk();

        $this->assertCount(1, $cart->json('data.items'));
        $this->assertSame(2, $cart->json('data.items.0.quantity'));
    }

    public function test_order_tracking_is_public_but_only_exposes_status(): void
    {
        $headers = $this->cartHeaders('guest-12');
        $this->addToCart($headers);
        $this->chooseRate($headers);

        $orderNumber = $this->withHeaders($headers)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->json('data.order.order_number');

        $tracking = $this->getJson("/api/v1/orders/{$orderNumber}/track")->assertOk();

        $this->assertSame('Pending', $tracking->json('data.status_label'));
        $this->assertNull($tracking->json('data.shipping_address'));
    }

    public function test_a_customer_can_cancel_a_pending_order_and_the_hold_is_released(): void
    {
        $customer = $this->makeCustomer();
        $headers = array_merge($this->userHeaders($customer), $this->cartHeaders('cancel-cart'));

        $this->addToCart($headers, 2);
        $this->chooseRate($headers);

        $orderNumber = $this->withHeaders($headers)
            ->postJson('/api/v1/checkout', $this->shippingPayload())
            ->json('data.order.order_number');

        $this->withHeaders($this->userHeaders($customer))
            ->postJson("/api/v1/user/orders/{$orderNumber}/cancel", ['reason' => 'Changed my mind'])
            ->assertOk();

        $this->assertSame(Status::ORDER_CANCELLED, Order::where('order_number', $orderNumber)->value('status'));

        $inventory = $this->product->branchInventories()->where('branch_id', $this->branch->id)->first();
        $this->assertSame(0, (int) $inventory->reserved_quantity);
        $this->assertSame(10, (int) $inventory->stock_quantity);
    }

    /**
     * A grouped product is a bundle listing with no price of its own. Adding
     * the parent used to succeed and put a zero-priced line in the cart, which
     * would have gone through checkout as a free order.
     */
    public function test_a_grouped_product_cannot_be_added_to_the_cart(): void
    {
        $set = $this->makeProduct($this->branch, 10, ['product_type' => 'grouped', 'regular_price' => 0]);

        $this->withHeaders($this->cartHeaders())
            ->postJson("/api/v1/cart/add/{$set->slug}", ['quantity' => 1])
            ->assertStatus(422);

        $this->withHeaders($this->cartHeaders())
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.summary.cart_count', 0);
    }

    public function test_an_external_product_cannot_be_added_to_the_cart(): void
    {
        $external = $this->makeProduct($this->branch, 10, [
            'product_type' => 'external',
            'product_url' => 'https://example.com/part',
        ]);

        $this->withHeaders($this->cartHeaders())
            ->postJson("/api/v1/cart/add/{$external->slug}", ['quantity' => 1])
            ->assertStatus(422);
    }

    public function test_a_simple_product_carries_its_price_and_tax_into_the_cart(): void
    {
        $product = $this->makeProduct($this->branch, 10, ['regular_price' => 250000]);

        $this->withHeaders($this->cartHeaders())
            ->postJson("/api/v1/cart/add/{$product->slug}", ['quantity' => 2])
            ->assertOk();

        $summary = $this->withHeaders($this->cartHeaders())
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(500000.0, (float) $summary['subtotal']);
        $this->assertSame(500000.0, (float) $summary['payable']);
    }
}
