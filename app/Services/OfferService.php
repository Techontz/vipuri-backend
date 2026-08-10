<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductVariation;

/**
 * Resolves the best running offer for a product (directly attached, or via one
 * of its categories) and computes the discounted price. Ported from the source
 * system's OfferManager.
 */
class OfferService
{
    public function bestOfferFor(Product $product): ?Offer
    {
        $direct = $product->offers()->running()->orderBy('priority')->get();

        $viaCategory = Offer::query()
            ->running()
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $product->categories->pluck('id')))
            ->orderBy('priority')
            ->get();

        return $direct->merge($viaCategory)
            ->sortBy('priority')
            ->first();
    }

    /**
     * @return array{original_price: float, final_price: float, offer_discount: float, offer_id: int|null}
     */
    public function priceAfterOffer(Product $product, ?ProductVariation $variation = null): array
    {
        $base = $variation ? (float) $variation->price : (float) $product->price;
        $offer = $this->bestOfferFor($product);

        if (! $offer) {
            return [
                'original_price' => $base,
                'final_price' => $base,
                'offer_discount' => 0.0,
                'offer_id' => null,
            ];
        }

        $discount = $offer->discountFor($base);

        return [
            'original_price' => $base,
            'final_price' => max(0, $base - $discount),
            'offer_discount' => $discount,
            'offer_id' => $offer->id,
        ];
    }
}
