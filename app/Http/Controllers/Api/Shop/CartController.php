<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\CartService;
use App\Services\CouponService;
use Illuminate\Http\Request;
use RuntimeException;

class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CouponService $coupons,
    ) {}

    public function index()
    {
        return responseSuccess('cart', 'Cart fetched', [
            'items' => $this->cart->items(),
            'summary' => $this->cart->summary(),
        ]);
    }

    public function add(Request $request, string $slug)
    {
        $data = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'attribute_values' => ['nullable', 'array'],
            'attribute_values.*' => ['integer'],
            'single' => ['nullable', 'boolean'],
        ]);

        $result = $this->cart->add(
            $slug,
            $data['attribute_values'] ?? [],
            (int) ($data['quantity'] ?? 1),
            (bool) ($data['single'] ?? false),
        );

        if (! empty($result['requires_options'])) {
            return responseSuccess('requires_options', 'Please choose the product options', [
                'requires_options' => true,
                'product_slug' => $result['product_slug'],
            ]);
        }

        return responseSuccess('added_to_cart', 'Product added to cart', [
            'item' => $result['item'],
            'cart_count' => $result['count'],
            'summary' => $result['summary'],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $result = $this->cart->update($data['id'], $data['quantity']);

        return responseSuccess('cart_updated', 'Cart updated', [
            'item' => $result['item'],
            'summary' => $result['summary'],
            'items' => $this->cart->items(),
        ]);
    }

    public function remove(Request $request)
    {
        $data = $request->validate(['id' => ['required', 'integer']]);

        $result = $this->cart->remove($data['id']);

        return responseSuccess('cart_item_removed', 'Item removed from cart', [
            'summary' => $result['summary'],
            'cart_count' => $result['count'],
            'items' => $this->cart->items(),
        ]);
    }

    public function clear()
    {
        $this->cart->clear();

        return responseSuccess('cart_cleared', 'Cart cleared', [
            'summary' => $this->cart->summary(),
            'items' => [],
        ]);
    }

    public function shippingRates(Request $request)
    {
        $rates = $this->cart->shippingRates($request->integer('zone_id') ?: null);

        return responseSuccess('shipping_rates', 'Shipping rates fetched', [
            'rates' => $rates->map(fn ($rate) => [
                'id' => $rate->id,
                'amount' => (float) $rate->amount,
                'expected_delivery_days' => (int) $rate->expected_delivery_days,
                'is_cod' => (bool) $rate->is_cod,
                'method' => [
                    'id' => $rate->method?->id,
                    'name' => $rate->method?->name,
                    'description' => $rate->method?->description,
                    'image' => fileUrl('shipping', $rate->method?->image),
                ],
                'zone' => [
                    'id' => $rate->zone?->id,
                    'name' => $rate->zone?->name,
                ],
            ])->values(),
        ]);
    }

    public function chooseShippingRate(Request $request)
    {
        $data = $request->validate(['shipping_rate_id' => ['required', 'integer']]);

        return responseSuccess('shipping_rate_selected', 'Delivery option selected', [
            'summary' => $this->cart->chooseShippingRate($data['shipping_rate_id']),
        ]);
    }

    public function chooseBranch(Request $request)
    {
        $data = $request->validate(['branch_id' => ['nullable', 'integer', 'exists:branches,id']]);

        if (! empty($data['branch_id']) && ! Branch::active()->whereKey($data['branch_id'])->exists()) {
            throw new RuntimeException('This branch is not available');
        }

        return responseSuccess('branch_selected', 'Branch selected', [
            'summary' => $this->cart->chooseBranch($data['branch_id'] ?? null),
        ]);
    }

    public function applyCoupon(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:80']]);

        $result = $this->coupons->apply($data['code'], $this->cart->items());

        return responseSuccess('coupon_applied', 'Coupon applied', [
            'coupon' => [
                'code' => $result['coupon']->code,
                'name' => $result['coupon']->name,
                'discount_amount' => $result['discount'],
            ],
            'summary' => $this->cart->summary(),
        ]);
    }

    public function removeCoupon()
    {
        $this->coupons->remove();

        return responseSuccess('coupon_removed', 'Coupon removed', [
            'summary' => $this->cart->summary(),
        ]);
    }
}
