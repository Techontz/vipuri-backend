<?php

namespace App\Services;

use App\Constants\Status;
use App\Models\Coupon;
use App\Models\CouponUsage;
use RuntimeException;

/**
 * Coupon validation and discount calculation, ported from the source system's
 * CouponManager.
 */
class CouponService
{
    public function __construct(private readonly CartIdentity $identity) {}

    /**
     * Validate a code against the current cart and return the discount.
     *
     * @param  array<int, array<string, mixed>>  $items  Normalised cart items.
     *
     * @throws RuntimeException with a user-facing message when invalid.
     */
    public function calculate(string $code, array $items): array
    {
        $coupon = Coupon::query()->active()->where('code', $code)->first();

        if (! $coupon) {
            throw new RuntimeException('Invalid coupon code');
        }

        if ($coupon->isExpired()) {
            throw new RuntimeException('This coupon has expired');
        }

        if ($coupon->limit_per_coupon && $coupon->total_uses >= $coupon->limit_per_coupon) {
            throw new RuntimeException('This coupon has reached its usage limit');
        }

        $userId = $this->identity->userId();

        if ($userId && $coupon->limit_per_customer) {
            $used = CouponUsage::where('coupon_id', $coupon->id)->where('user_id', $userId)->count();

            if ($used >= $coupon->limit_per_customer) {
                throw new RuntimeException('You have already used this coupon the maximum number of times');
            }
        }

        $eligible = $this->eligibleItems($coupon, $items);

        if (empty($eligible)) {
            throw new RuntimeException('This coupon does not apply to any item in your cart');
        }

        $eligibleTotal = array_sum(array_map(
            fn ($item) => $item['price'] * $item['quantity'],
            $eligible
        ));

        if ($coupon->minimum_spend > 0 && $eligibleTotal < $coupon->minimum_spend) {
            throw new RuntimeException('Minimum spend for this coupon is ' . showAmount($coupon->minimum_spend));
        }

        if ($coupon->maximum_spend > 0 && $eligibleTotal > $coupon->maximum_spend) {
            throw new RuntimeException('Maximum spend for this coupon is ' . showAmount($coupon->maximum_spend));
        }

        $discount = match (true) {
            $coupon->isPercent() => ($eligibleTotal * (float) $coupon->amount) / 100,
            $coupon->isFixedCart() => (float) $coupon->amount,
            $coupon->isFixedProduct() => array_sum(array_map(
                fn ($item) => min((float) $coupon->amount, (float) $item['price']) * $item['quantity'],
                $eligible
            )),
            default => 0.0,
        };

        if ($coupon->max_discount > 0) {
            $discount = min($discount, (float) $coupon->max_discount);
        }

        $discount = round(min($discount, $eligibleTotal), 2);

        return ['coupon' => $coupon, 'discount' => $discount];
    }

    /** Persist the applied coupon for this shopper. */
    public function apply(string $code, array $items): array
    {
        $result = $this->calculate($code, $items);

        $context = $this->identity->context();
        $context->coupon_id = $result['coupon']->id;
        $context->coupon_discount = $result['discount'];
        $context->save();

        return $result;
    }

    public function remove(): void
    {
        $context = $this->identity->context();
        $context->coupon_id = null;
        $context->coupon_discount = 0;
        $context->save();
    }

    /**
     * Re-evaluate the stored coupon against the current cart. Returns null and
     * clears the coupon when it is no longer valid (e.g. the qualifying item
     * was removed) so totals can never go stale.
     */
    public function appliedCoupon(array $items): ?array
    {
        $context = $this->identity->context();

        if (! $context->coupon_id) {
            return null;
        }

        $coupon = Coupon::find($context->coupon_id);

        if (! $coupon) {
            $this->remove();

            return null;
        }

        try {
            $result = $this->calculate($coupon->code, $items);
        } catch (RuntimeException) {
            $this->remove();

            return null;
        }

        if ((float) $context->coupon_discount !== (float) $result['discount']) {
            $context->coupon_discount = $result['discount'];
            $context->save();
        }

        return $result;
    }

    /** Items a coupon may discount, honouring product/category restrictions. */
    private function eligibleItems(Coupon $coupon, array $items): array
    {
        $productIds = $coupon->products()->pluck('products.id')->all();
        $categoryIds = $coupon->categories()->pluck('categories.id')->all();

        return array_values(array_filter($items, function ($item) use ($coupon, $productIds, $categoryIds) {
            if ($coupon->exclude_sale_items && ! empty($item['is_on_sale'])) {
                return false;
            }

            if ($coupon->exclude_offers && ! empty($item['offer_id'])) {
                return false;
            }

            if (empty($productIds) && empty($categoryIds)) {
                return true;
            }

            if (in_array($item['product_id'], $productIds, false)) {
                return true;
            }

            return (bool) array_intersect($categoryIds, $item['category_ids'] ?? []);
        }));
    }

    /** Record the usage of a coupon once an order is created. */
    public function recordUsage(Coupon $coupon, ?int $userId, int $orderId): void
    {
        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $userId ?? 0,
            'order_id' => $orderId,
        ]);

        $coupon->increment('total_uses');
    }

    public function discountTypeLabel(Coupon $coupon): string
    {
        return match ((int) $coupon->discount_type) {
            Status::COUPON_DISCOUNT_PERCENT => 'Percentage',
            Status::COUPON_DISCOUNT_FIXED_CART => 'Fixed cart discount',
            Status::COUPON_DISCOUNT_FIXED_PRODUCT => 'Fixed product discount',
            default => 'Unknown',
        };
    }
}
