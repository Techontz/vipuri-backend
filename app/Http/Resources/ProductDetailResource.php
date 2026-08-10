<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $regular = (float) $this->regular_price;
        $price = (float) $this->display_price;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'gtin' => $this->gtin,
            'product_type' => $this->product_type,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'specifications' => $this->normaliseSpecifications(),
            'sample_pdf' => fileUrl('download', $this->sample_pdf),

            'regular_price' => $regular,
            'sale_price' => (float) $this->sale_price,
            'price' => $price,
            'discount_percent' => $regular > 0 && $price < $regular
                ? round((($regular - $price) / $regular) * 100)
                : 0,
            'is_on_sale' => $this->isOnSale(),
            'sale_is_scheduled' => (bool) $this->sale_is_scheduled,
            'schedule_sale_start' => $this->schedule_sale_start?->toIso8601String(),
            'schedule_sale_end' => $this->schedule_sale_end?->toIso8601String(),

            'tax' => $this->tax ? [
                'name' => $this->tax->name,
                'rate' => (float) $this->tax->rate,
                'status' => $this->tax_status,
                'show' => (bool) $this->show_tax,
            ] : null,

            'inventory' => [
                'track_inventory' => $this->trackInventory(),
                'stock_quantity' => (int) $this->stock_quantity,
                'display_available' => (bool) $this->display_available,
                'display_stock_quantity' => (bool) $this->display_stock_quantity,
                'min_stock_quantity' => (int) $this->min_stock_quantity,
                'threshold_quantity' => (int) $this->threshold_quantity,
                'low_stock_activity' => (int) $this->low_stock_activity,
                'allow_backorder' => (bool) $this->allow_backorder,
                'is_buyable' => $this->isBuyable(),
                'unit' => $this->stockUnit?->name,
            ],

            'min_cart_quantity' => (int) $this->min_cart_quantity,
            'max_cart_quantity' => (int) $this->max_cart_quantity,

            'shipping' => [
                'weight' => (float) $this->weight,
                'length' => (float) $this->length,
                'width' => (float) $this->width,
                'height' => (float) $this->height,
                'class' => $this->shipping_class,
            ],

            'vehicle' => [
                'year' => $this->vehicle_year,
                'model' => $this->vehicle_model,
                'engine' => $this->vehicle_engine,
                'engine_type' => $this->vehicle_engine_type,
            ],

            'product_url' => $this->product_url,
            'button_text' => $this->button_text,

            'brand' => $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
                'logo' => fileUrl('brand', $this->brand->logo),
            ] : null,

            'categories' => $this->categories->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug,
            ])->values(),

            'gallery' => $this->allMedia
                ->sortByDesc('is_main')
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'url' => fileUrl('product', $m->path),
                    'thumb' => fileUrl('product', $m->path, true),
                    'is_main' => (bool) $m->is_main,
                    'variation_id' => (int) $m->variation_id,
                    'video_link' => $m->video_link,
                ])->values(),

            'attributes' => $this->attributes->map(fn ($attribute) => [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'control_type' => $attribute->control_type,
                'is_visible' => (bool) optional(
                    $this->productAttributes->firstWhere('attribute_id', $attribute->id)
                )->is_visible,
                'values' => $attribute->values->map(fn ($value) => [
                    'id' => $value->id,
                    'name' => $value->name,
                    'color_code' => $value->color_code,
                    'image' => fileUrl('product', $value->image),
                    'is_pre_selected' => (bool) $value->is_pre_selected,
                ])->values(),
            ])->values(),

            'variations' => $this->variations->map(fn ($v) => [
                'id' => $v->id,
                'attribute_values' => array_map('intval', $v->attribute_values ?? []),
                'name' => $v->name,
                'sku' => $v->sku,
                'regular_price' => (float) $v->regular_price,
                'sale_price' => (float) $v->sale_price,
                'price' => (float) $v->price,
                'is_on_sale' => $v->isOnSale(),
                'stock_quantity' => (int) $v->stock_quantity,
                'display_stock_quantity' => (bool) $v->display_stock_quantity,
                'display_available' => (bool) $v->display_available,
                'track_inventory' => $v->trackInventory(),
                'allow_backorder' => (bool) $v->allow_backorder,
                'is_buyable' => $v->isBuyable(),
                'min_cart_quantity' => (int) $v->min_cart_quantity,
                'max_cart_quantity' => (int) $v->max_cart_quantity,
                'images' => $v->media->map(fn ($m) => [
                    'url' => fileUrl('product', $m->path),
                    'thumb' => fileUrl('product', $m->path, true),
                ])->values(),
            ])->values(),

            'grouped_products' => $this->groupedProducts
                ->filter(fn ($g) => $g->groupedProduct)
                ->map(fn ($g) => new ProductCardResource($g->groupedProduct))
                ->values(),

            'up_sells' => $this->productUpSells
                ->filter(fn ($u) => $u->linkedProduct)
                ->map(fn ($u) => new ProductCardResource($u->linkedProduct))
                ->values(),

            'cross_sells' => $this->productCrossSells
                ->filter(fn ($c) => $c->linkedProduct)
                ->map(fn ($c) => new ProductCardResource($c->linkedProduct))
                ->values(),

            'avg_rating' => round($this->avg_rating_calc, 2),
            'total_reviews' => $this->total_review_calc,
            'rating_breakdown' => $this->ratingBreakdown(),

            'branch_availability' => $this->whenLoaded('branchInventories', fn () => $this->branchInventories
                ->filter(fn ($row) => $row->branch?->status)
                ->map(fn ($row) => [
                    'branch_id' => $row->branch_id,
                    'branch_name' => $row->branch?->name,
                    'city' => $row->branch?->city,
                    'variation_id' => (int) $row->variation_id,
                    'in_stock' => $row->available_quantity > 0,
                    'quantity' => $row->available_quantity,
                ])->values()),

            'meta' => [
                'title' => $this->meta_title,
                'description' => $this->meta_description,
                'keywords' => $this->meta_keywords,
            ],
        ];
    }

    /** Source stored specifications as {key:[], value:[]}; expose pairs. */
    private function normaliseSpecifications(): array
    {
        $keys = (array) ($this->specifications?->key ?? []);
        $values = (array) ($this->specifications?->value ?? []);
        $pairs = [];

        foreach ($keys as $index => $key) {
            if ($key === null || $key === '') {
                continue;
            }

            $pairs[] = ['key' => $key, 'value' => $values[$index] ?? ''];
        }

        return $pairs;
    }

    private function ratingBreakdown(): array
    {
        $counts = $this->reviews()
            ->approved()
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating')
            ->all();

        $breakdown = [];

        for ($star = 5; $star >= 1; $star--) {
            $breakdown[] = ['rating' => $star, 'count' => (int) ($counts[$star] ?? 0)];
        }

        return $breakdown;
    }
}
