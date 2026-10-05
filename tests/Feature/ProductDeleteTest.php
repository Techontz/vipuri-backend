<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Constants\Status;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting a product must never rewrite order history.
 *
 * order_items cascade on product delete, so a product that was ever ordered
 * is refused; one that never traded is removed outright.
 */
class ProductDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    public function test_a_product_that_never_traded_is_deleted(): void
    {
        $product = $this->makeProduct(Branch::first());

        $this->withHeaders($this->adminHeaders($this->makeStaff(Roles::SUPER_ADMIN)))
            ->deleteJson("/api/v1/admin/products/{$product->id}")
            ->assertOk();

        $this->assertNull(Product::find($product->id));
    }

    public function test_a_product_on_an_order_is_refused_and_the_order_kept(): void
    {
        $branch = Branch::first();
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
            'order_id' => $order->id, 'product_id' => $product->id, 'variation_id' => 0,
            'quantity' => 1, 'price' => 100000, 'subtotal' => 100000,
        ]);

        $this->withHeaders($this->adminHeaders($this->makeStaff(Roles::SUPER_ADMIN)))
            ->deleteJson("/api/v1/admin/products/{$product->id}")
            ->assertStatus(422);

        $this->assertNotNull(Product::find($product->id));
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_branch_staff_cannot_delete_products(): void
    {
        $branch = Branch::first();
        $product = $this->makeProduct($branch);

        $this->withHeaders($this->adminHeaders($this->makeStaff(Roles::BRANCH_MANAGER, $branch)))
            ->deleteJson("/api/v1/admin/products/{$product->id}")
            ->assertForbidden();

        $this->assertNotNull(Product::find($product->id));
    }
}
