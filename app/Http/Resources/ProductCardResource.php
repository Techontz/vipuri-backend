<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact product payload used by grids, carousels and search results.
 * Mirrors every field the source product card rendered.
 */
class ProductCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $regular = (float) $this->regular_price;
        $price = (float) $this->display_price;
        $discount = $regular > 0 && $price < $regular
            ? round((($regular - $price) / $regular) * 100)
            : 0;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'product_type' => $this->product_type,
            'short_description' => $this->short_description,
            'image' => fileUrl('product', $this->main_image, true),
            'image_full' => fileUrl('product', $this->main_image),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
                'logo' => fileUrl('brand', $this->brand->logo),
            ] : null),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug,
            ])->values()),
            'regular_price' => $regular,
            'price' => $price,
            'discount_percent' => $discount,
            'is_on_sale' => $this->isOnSale(),
            'is_featured' => (bool) $this->is_featured,
            'show_deals' => (bool) $this->show_deals,
            'limited_stock' => (bool) $this->limited_stock,
            'avg_rating' => round((float) ($this->reviews_avg_rating ?? $this->avg_rating_calc), 2),
            'total_reviews' => (int) ($this->reviews_count ?? $this->total_review_calc),
            'stock_quantity' => (int) $this->stock_quantity,
            'display_stock_quantity' => (bool) $this->display_stock_quantity,
            'display_available' => (bool) $this->display_available,
            'track_inventory' => $this->trackInventory(),
            'is_buyable' => $this->isBuyable(),
            'stock_unit' => $this->whenLoaded('stockUnit', fn () => $this->stockUnit?->name),
            'min_cart_quantity' => (int) $this->min_cart_quantity,
            'max_cart_quantity' => (int) $this->max_cart_quantity,
            'product_url' => $this->product_url,
            'button_text' => $this->button_text,
            'vehicle' => [
                'year' => $this->vehicle_year,
                'model' => $this->vehicle_model,
                'engine' => $this->vehicle_engine,
                'engine_type' => $this->vehicle_engine_type,
            ],
        ];
    }
}
