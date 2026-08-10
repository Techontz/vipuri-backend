<?php

namespace App\Services;

use App\Models\AttributeValue;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ShippingRate;
use App\Models\Wishlist;
use RuntimeException;

/**
 * Cart engine. Mirrors the source system's CartManager, adapted to the
 * stateless API (see {@see CartIdentity}) and branch-aware stock checks.
 */
class CartService
{
    public function __construct(
        private readonly CartIdentity $identity,
        private readonly OfferService $offers,
        private readonly CouponService $coupons,
        private readonly InventoryService $inventory,
    ) {}

    /* ------------------------------------------------------------------ *
     | Mutations
     * ------------------------------------------------------------------ */

    /**
     * Add a product to the cart.
     *
     * @param  array<int>  $attributeValues  Selected attribute value ids (variable products).
     * @param  bool  $single  True when adding straight from a product card; variable
     *                        products then need the customer to pick options first.
     *
     * @throws RuntimeException with a user-facing message.
     */
    public function add(string $slug, array $attributeValues = [], int $quantity = 1, bool $single = false): array
    {
        if ($this->identity->isAnonymousWithoutToken()) {
            throw new RuntimeException('Missing cart token');
        }

        $product = Product::active()
            ->with('attributes.values', 'stockUnit', 'variations')
            ->where('slug', $slug)
            ->first();

        if (! $product) {
            throw new RuntimeException('No product found');
        }

        if ($quantity < 1) {
            throw new RuntimeException('Minimum quantity is 1');
        }

        if ($product->isExternal()) {
            throw new RuntimeException('This product is sold on an external site');
        }

        // A grouped product is a bundle listing with no price of its own — the
        // customer adds its members from the product page. Adding the parent
        // would put a zero-priced line in the cart.
        if ($product->isGrouped()) {
            throw new RuntimeException('Choose the items you want from this set');
        }

        if ($single && ! $product->isSimple()) {
            return ['requires_options' => true, 'product_slug' => $product->slug];
        }

        if ($product->isSimple() && ! $product->isBuyable()) {
            throw new RuntimeException('The product is not available to purchase');
        }

        [$variation, $encodedAttributes] = $this->resolveVariation($product, $attributeValues);

        $existing = $this->findRow($product->id, $variation?->id);
        $totalQty = $quantity + ($existing?->quantity ?? 0);

        $this->assertCartQuantityLimits($product, $variation, $totalQty);
        $this->assertStock($product, $variation, $totalQty);

        $priceData = $this->offers->priceAfterOffer($product, $variation);

        if ($existing) {
            $existing->fill([
                'quantity' => $totalQty,
                'price' => $priceData['final_price'],
                'original_price' => $priceData['original_price'],
                'offer_discount' => $priceData['offer_discount'],
                'offer_id' => $priceData['offer_id'],
            ])->save();

            $row = $existing;
        } else {
            $row = Cart::create($this->identity->ownerAttributes() + [
                'product_id' => $product->id,
                'variation_id' => $variation?->id,
                'variation_attributes' => $variation
                    ? json_encode($variation->attribute_values)
                    : $encodedAttributes,
                'quantity' => $quantity,
                'price' => $priceData['final_price'],
                'original_price' => $priceData['original_price'],
                'offer_discount' => $priceData['offer_discount'],
                'offer_id' => $priceData['offer_id'],
            ]);
        }

        return [
            'item' => $row,
            'count' => $this->itemsCount(),
            'summary' => $this->summary(),
        ];
    }

    public function update(int $cartId, int $quantity): array
    {
        $row = $this->identity->scope(Cart::query())->findOrFail($cartId);
        $product = $row->product;
        $variation = $row->variation;

        if ($quantity < 1) {
            throw new RuntimeException('Minimum quantity is 1');
        }

        $this->assertCartQuantityLimits($product, $variation, $quantity);
        $this->assertStock($product, $variation, $quantity);

        $priceData = $this->offers->priceAfterOffer($product, $variation);

        $row->fill([
            'quantity' => $quantity,
            'price' => $priceData['final_price'],
            'original_price' => $priceData['original_price'],
            'offer_discount' => $priceData['offer_discount'],
            'offer_id' => $priceData['offer_id'],
        ])->save();

        return ['item' => $row->fresh(), 'summary' => $this->summary()];
    }

    public function remove(int $cartId): array
    {
        $row = $this->identity->scope(Cart::query())->findOrFail($cartId);
        $row->delete();

        return ['summary' => $this->summary(), 'count' => $this->itemsCount()];
    }

    public function clear(?int $userId = null, ?string $sessionId = null): void
    {
        $query = Cart::query();

        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($sessionId) {
            $query->where('session_id', $sessionId);
        } else {
            $this->identity->scope($query);
        }

        $query->delete();
    }

    /** Move a guest cart + wishlist onto the account after login. */
    public function mergeGuestData(int $userId, ?string $guestToken): void
    {
        if (! $guestToken) {
            return;
        }

        foreach (Cart::where('session_id', $guestToken)->get() as $guestRow) {
            $existing = Cart::where('user_id', $userId)
                ->where('product_id', $guestRow->product_id)
                ->where('variation_id', $guestRow->variation_id)
                ->first();

            if ($existing) {
                $existing->quantity += $guestRow->quantity;
                $existing->save();
                $guestRow->delete();

                continue;
            }

            $guestRow->update(['user_id' => $userId, 'session_id' => null]);
        }

        foreach (Wishlist::where('session_id', $guestToken)->get() as $guestWish) {
            $exists = Wishlist::where('user_id', $userId)
                ->where('product_id', $guestWish->product_id)
                ->exists();

            $exists
                ? $guestWish->delete()
                : $guestWish->update(['user_id' => $userId, 'session_id' => null]);
        }
    }

    /* ------------------------------------------------------------------ *
     | Reads
     * ------------------------------------------------------------------ */

    public function rows()
    {
        return $this->identity->scope(Cart::query())
            ->with([
                'product' => fn ($q) => $q->with(['categories:id', 'tax', 'media', 'offers']),
                'variation',
            ])
            ->orderBy('id')
            ->get();
    }

    /** Normalised cart items used by the API, coupon engine and checkout. */
    public function items(): array
    {
        return $this->rows()->map(function (Cart $row) {
            $product = $row->product;
            $taxRate = (float) ($product?->tax?->rate ?? 0);
            $taxable = $product?->tax && $product->tax_status === 'taxable';
            $price = (float) $row->price;
            $taxAmount = $taxable ? ($price * $taxRate) / 100 : 0.0;
            $afterTax = $price + $taxAmount;

            return [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'product_name' => $product?->name,
                'product_slug' => $product?->slug,
                'product_image' => fileUrl('product', $product?->main_image, true),
                'variation_id' => $row->variation_id,
                'variation_attributes' => $row->variation_attributes,
                'variations' => $this->variationLabels($row),
                'category_ids' => $product?->categories->pluck('id')->all() ?? [],
                'is_on_sale' => (bool) $product?->isOnSale(),
                'quantity' => (int) $row->quantity,
                'price' => $price,
                'original_price' => (float) $row->original_price,
                'offer_discount' => (float) $row->offer_discount,
                'offer_id' => $row->offer_id,
                'tax_name' => $product?->tax?->name,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'after_tax' => $afterTax,
                'subtotal' => $afterTax * $row->quantity,
                'stock_quantity' => $row->variation
                    ? (int) $row->variation->stock_quantity
                    : (int) ($product?->stock_quantity ?? 0),
                'max_cart_quantity' => (int) ($row->variation?->max_cart_quantity ?: $product?->max_cart_quantity ?: 0),
                'min_cart_quantity' => (int) ($row->variation?->min_cart_quantity ?: $product?->min_cart_quantity ?: 1),
                'created_at' => $row->created_at?->toDateTimeString(),
            ];
        })->all();
    }

    public function itemsCount(): int
    {
        return $this->identity->scope(Cart::query())->count();
    }

    public function isEmpty(): bool
    {
        return $this->itemsCount() === 0;
    }

    /**
     * Cart totals. Discount is recomputed from the stored coupon on every read
     * so a stale coupon can never survive a cart change.
     */
    public function summary(): array
    {
        $items = $this->items();

        $subtotal = 0.0;
        $totalTax = 0.0;
        $totalItems = 0;
        $totalOfferDiscount = 0.0;

        foreach ($items as $item) {
            $subtotal += $item['subtotal'];
            $totalTax += $item['tax_amount'] * $item['quantity'];
            $totalItems += $item['quantity'];
            $totalOfferDiscount += $item['offer_discount'] * $item['quantity'];
        }

        $applied = $this->coupons->appliedCoupon($items);
        $discount = (float) ($applied['discount'] ?? 0);

        $context = $this->identity->context();
        $shippingRate = $context->shipping_rate_id ? ShippingRate::find($context->shipping_rate_id) : null;
        $shippingCharge = (float) ($shippingRate?->amount ?? 0);

        return [
            'cart_count' => count($items),
            'total_items' => $totalItems,
            'subtotal' => round($subtotal, 2),
            'total_tax' => round($totalTax, 2),
            'total_offer_discount' => round($totalOfferDiscount, 2),
            'discount' => round($discount, 2),
            'coupon' => $applied ? [
                'id' => $applied['coupon']->id,
                'code' => $applied['coupon']->code,
                'name' => $applied['coupon']->name,
                'discount_amount' => round($discount, 2),
            ] : null,
            'shipping_rate_id' => $shippingRate?->id,
            'shipping_method_id' => $shippingRate?->shipping_method_id,
            'shipping_charge' => $shippingCharge,
            'branch_id' => $context->branch_id,
            'payable' => round(max(0, $subtotal - $discount + $shippingCharge), 2),
        ];
    }

    /** Shipping rates available for the current cart value. */
    public function shippingRates(?int $zoneId = null)
    {
        $subtotal = $this->summary()['subtotal'];

        return ShippingRate::query()
            ->active()
            ->when($zoneId, fn ($q) => $q->where('shipping_zone_id', $zoneId))
            ->where('min_order_amount', '<=', $subtotal)
            ->where(fn ($q) => $q->where('max_order_amount', '>=', $subtotal)->orWhere('max_order_amount', 0))
            ->with(['method', 'zone'])
            ->get();
    }

    public function chooseShippingRate(int $rateId): array
    {
        $rate = ShippingRate::active()->findOrFail($rateId);

        $context = $this->identity->context();
        $context->shipping_rate_id = $rate->id;
        $context->save();

        return $this->summary();
    }

    public function chooseBranch(?int $branchId): array
    {
        $context = $this->identity->context();
        $context->branch_id = $branchId;
        $context->save();

        return $this->summary();
    }

    /* ------------------------------------------------------------------ *
     | Internals
     * ------------------------------------------------------------------ */

    private function findRow(int $productId, ?int $variationId): ?Cart
    {
        return $this->identity->scope(Cart::query())
            ->where('product_id', $productId)
            ->where('variation_id', $variationId)
            ->first();
    }

    /**
     * @return array{0: ProductVariation|null, 1: string|null}
     */
    private function resolveVariation(Product $product, array $attributeValues): array
    {
        $attrs = array_values(array_unique(array_map('intval', $attributeValues)));
        sort($attrs);

        if (! $product->isVariable()) {
            return [null, $attrs ? json_encode($attrs) : null];
        }

        if (empty($attrs)) {
            $attrs = AttributeValue::query()
                ->where('is_pre_selected', 1)
                ->whereHas('attribute.productAttributes', fn ($q) => $q->where('product_id', $product->id))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();
        }

        if (empty($attrs)) {
            throw new RuntimeException('Please select the product options');
        }

        $variation = ProductVariation::where('product_id', $product->id)
            ->get()
            ->first(function (ProductVariation $v) use ($attrs) {
                $values = array_map('intval', $v->attribute_values ?? []);
                sort($values);

                return $values === $attrs;
            });

        if (! $variation) {
            throw new RuntimeException('Invalid variation selected');
        }

        if (! $variation->isBuyable()) {
            throw new RuntimeException('The product variant is not available to purchase');
        }

        return [$variation, json_encode($attrs)];
    }

    private function assertCartQuantityLimits(Product $product, ?ProductVariation $variation, int $quantity): void
    {
        $unit = $product->stockUnit?->name ?? '';
        $min = (int) ($variation?->min_cart_quantity ?: $product->min_cart_quantity);
        $max = (int) ($variation?->max_cart_quantity ?: $product->max_cart_quantity);

        if ($min > 0 && $quantity < $min) {
            throw new RuntimeException(trim("Minimum cart quantity for this item is $min $unit"));
        }

        if ($max > 0 && $quantity > $max) {
            throw new RuntimeException(trim("Maximum cart quantity for this item is $max $unit"));
        }
    }

    private function assertStock(Product $product, ?ProductVariation $variation, int $quantity): void
    {
        $tracks = $variation ? $variation->trackInventory() : $product->trackInventory();
        $backorder = $variation ? $variation->allow_backorder : $product->allow_backorder;

        if (! $tracks || $backorder) {
            return;
        }

        $available = $this->inventory->sellableQuantity($product->id, $variation?->id ?? 0);

        if ($quantity > $available) {
            throw new RuntimeException('Requested quantity not available in stock');
        }
    }

    /** Human-readable "Attribute: Value" pairs for a cart row. */
    private function variationLabels(Cart $row): array
    {
        $ids = $row->variation
            ? ($row->variation->attribute_values ?? [])
            : (json_decode((string) $row->variation_attributes, true) ?: []);

        if (! $ids) {
            return [];
        }

        return AttributeValue::with('attribute')
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn ($value) => [$value->attribute?->name ?? 'Option' => $value->name])
            ->all();
    }
}
