<?php

namespace App\Http\Controllers\Api\Shop;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCardResource;
use App\Http\Resources\ProductDetailResource;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Storefront catalogue: listing, filtering, sorting, product detail, reviews.
 *
 * Filter and sort semantics are ported verbatim from the source system so the
 * replicated UI behaves identically.
 */
class CatalogController extends Controller
{
    public function categories(Request $request)
    {
        $categories = Category::query()
            ->active()
            ->when($request->boolean('parents_only', true), fn ($q) => $q->isParent())
            ->when($request->boolean('navbar'), fn ($q) => $q->showInNavbar())
            ->when($request->boolean('top'), fn ($q) => $q->top())
            ->when($request->boolean('popular'), fn ($q) => $q->popular())
            ->with(['subcategories' => fn ($q) => $q->active()->with(['subcategories' => fn ($sq) => $sq->active()])])
            ->withCount(['products' => fn ($q) => $q->where('products.status', Status::ENABLE)])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return responseSuccess('categories', 'Categories fetched', [
            'categories' => $this->mapCategories($categories),
        ]);
    }

    public function category(string $slug)
    {
        $category = Category::active()->where('slug', $slug)->firstOrFail();

        $category->load(['subcategories' => fn ($q) => $q->active()]);

        return responseSuccess('category', 'Category fetched', [
            'category' => $this->mapCategory($category),
        ]);
    }

    public function brands(Request $request)
    {
        $brands = Brand::query()
            ->active()
            ->when($request->boolean('popular'), fn ($q) => $q->popular())
            ->withCount(['products' => fn ($q) => $q->where('products.status', Status::ENABLE)])
            ->orderBy('name')
            ->get()
            ->map(fn ($brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo' => fileUrl('brand', $brand->logo),
                'is_popular' => (bool) $brand->is_popular,
                'products_count' => $brand->products_count,
            ]);

        return responseSuccess('brands', 'Brands fetched', ['brands' => $brands]);
    }

    /** Product listing with every filter the source system supported. */
    public function products(Request $request)
    {
        $query = Product::query()->active();

        $category = null;
        $brand = null;

        if ($slug = $request->query('category')) {
            $category = Category::active()->where('slug', $slug)->firstOrFail();
            $ids = $category->descendantIds();
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $ids));
        }

        if ($slug = $request->query('brand_slug')) {
            $brand = Brand::active()->where('slug', $slug)->firstOrFail();
            $query->where('brand_id', $brand->id);
        }

        if ($offers = array_filter((array) $request->query('offer', []))) {
            $query->where(function ($q) use ($offers) {
                $q->whereHas('offers', fn ($sub) => $sub->whereIn('offers.id', $offers))
                    ->orWhereHas('categories.offers', fn ($sub) => $sub->whereIn('offers.id', $offers));
            });
        }

        if ($campaignId = $request->query('campaign')) {
            $query->whereHas('campaigns', fn ($q) => $q->running()->where('campaigns.id', $campaignId));
        }

        if ($request->boolean('deals')) {
            $query->deals();
        }

        if ($request->boolean('featured')) {
            $query->featured();
        }

        $priceRange = $this->priceRange(clone $query);

        $this->applyFilters($query, $request);

        $query->with([
                'brand',
                'categories:id,name,slug',
                'media',
                'stockUnit',
                'variations',
                'groupedProducts.groupedProduct.variations',
                'offers' => fn ($q) => $q->running(),
            ])
            ->withAvg(['reviews as reviews_avg_rating' => fn ($q) => $q->approved()], 'rating')
            ->withCount(['reviews as reviews_count' => fn ($q) => $q->approved()]);

        $this->applySort($query, $request->query('sort_by', 'new_release'));

        $products = $query->paginate(getPaginate())->withQueryString();

        return responseSuccess('products', 'Products fetched', [
            'products' => ProductCardResource::collection($products->items()),
            'pagination' => $this->pagination($products),
            'filters' => [
                'min_price' => $priceRange['min'],
                'max_price' => $priceRange['max'],
            ],
            'category' => $category ? $this->mapCategory($category) : null,
            'brand' => $brand ? [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo' => fileUrl('brand', $brand->logo),
                'seo_content' => $brand->seo_content,
            ] : null,
        ]);
    }

    /** Options for the vehicle finder (year / brand / model / engine). */
    public function vehicleFilters()
    {
        $distinct = fn (string $column) => Product::query()
            ->active()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column, $column === 'vehicle_year' ? 'desc' : 'asc')
            ->pluck($column)
            ->values();

        return responseSuccess('vehicle_filters', 'Vehicle filter options fetched', [
            'years' => $distinct('vehicle_year'),
            'models' => $distinct('vehicle_model'),
            'engines' => $distinct('vehicle_engine'),
            'engine_types' => $distinct('vehicle_engine_type'),
            'brands' => Brand::active()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function show(string $slug)
    {
        $product = Product::active()
            ->where('slug', $slug)
            ->with([
                'brand',
                'categories:id,name,slug',
                'tax',
                'stockUnit',
                'allMedia',
                'attributes.values',
                'productAttributes',
                'variations.media',
                'groupedProducts.groupedProduct' => fn ($q) => $q->with('media', 'variations', 'brand'),
                'productUpSells.linkedProduct' => fn ($q) => $q->with('media', 'variations', 'brand'),
                'productCrossSells.linkedProduct' => fn ($q) => $q->with('media', 'variations', 'brand'),
                'branchInventories.branch',
                'offers' => fn ($q) => $q->running(),
            ])
            ->firstOrFail();

        // Related products, only when the merchandiser has not set up up-sells.
        $related = collect();

        if ($product->productUpSells->isEmpty()) {
            $categoryIds = $product->categories->pluck('id')->all();

            $related = Product::active()
                ->where('id', '!=', $product->id)
                ->when($categoryIds, fn ($q) => $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds)))
                ->with('media', 'brand', 'variations', 'stockUnit')
                ->withAvg(['reviews as reviews_avg_rating' => fn ($q) => $q->approved()], 'rating')
                ->withCount(['reviews as reviews_count' => fn ($q) => $q->approved()])
                ->latest('id')
                ->limit(10)
                ->get();
        }

        return responseSuccess('product', 'Product fetched', [
            'product' => new ProductDetailResource($product),
            'related_products' => ProductCardResource::collection($related),
        ]);
    }

    /** Lightweight payload for the quick-view modal. */
    public function quickView(string $slug)
    {
        $product = Product::active()
            ->where('slug', $slug)
            ->with([
                'brand', 'categories:id,name,slug', 'tax', 'stockUnit', 'allMedia',
                'attributes.values', 'productAttributes', 'variations.media',
                'groupedProducts.groupedProduct', 'productUpSells', 'productCrossSells',
                'offers' => fn ($q) => $q->running(),
            ])
            ->firstOrFail();

        return responseSuccess('quick_view_product', 'Quick view of product', [
            'product' => new ProductDetailResource($product),
        ]);
    }

    public function reviews(Request $request, int $productId)
    {
        $reviews = ProductReview::approved()
            ->where('product_id', $productId)
            ->with(['user:id,firstname,lastname,username,image', 'productReviewReply'])
            ->orderByDesc('id')
            ->paginate(getPaginate(10));

        return responseSuccess('product_review', 'Product reviews fetched', [
            'reviews' => collect($reviews->items())->map(fn ($review) => [
                'id' => $review->id,
                'rating' => (int) $review->rating,
                'review' => $review->review,
                'images' => collect($review->images ?? [])->map(fn ($img) => fileUrl('review', $img))->values(),
                'user' => [
                    'name' => trim(($review->user->firstname ?? '') . ' ' . ($review->user->lastname ?? '')),
                    'username' => $review->user?->username,
                    'image' => fileUrl('userProfile', $review->user?->image),
                ],
                'reply' => $review->productReviewReply ? [
                    'comment' => $review->productReviewReply->comment,
                    'created_at' => $review->productReviewReply->created_at?->toIso8601String(),
                ] : null,
                'created_at' => $review->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => $this->pagination($reviews),
            'is_last_page' => ! $reviews->hasMorePages(),
        ]);
    }

    /** Running offers and campaigns, for the offer/deal sections. */
    public function offers()
    {
        return responseSuccess('offers', 'Offers fetched', [
            'offers' => Offer::running()->orderBy('priority')->get()->map(fn ($offer) => [
                'id' => $offer->id,
                'name' => $offer->name,
                'description' => $offer->description,
                'image' => fileUrl('offer', $offer->image),
                'discount_type' => (int) $offer->discount_type,
                'amount' => (float) $offer->amount,
                'start_at' => $offer->start_at?->toIso8601String(),
                'end_at' => $offer->end_at?->toIso8601String(),
                'show_on_section' => (bool) $offer->show_on_section,
            ]),
            'campaigns' => Campaign::running()->get()->map(fn ($campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'description' => $campaign->description,
                'image' => fileUrl('campaign', $campaign->image),
                'banner' => fileUrl('campaign', $campaign->banner),
                'discount_type' => (int) $campaign->discount_type,
                'amount' => (float) $campaign->amount,
                'start_at' => $campaign->start_at?->toIso8601String(),
                'end_at' => $campaign->end_at?->toIso8601String(),
                'show_on_section' => (bool) $campaign->show_on_section,
            ]),
        ]);
    }

    /* ------------------------------------------------------------------ *
     | Internals
     * ------------------------------------------------------------------ */

    private function applyFilters(Builder $query, Request $request): void
    {
        $query->when($request->query('search'), function ($q, $search) {
            $q->where(function ($inner) use ($search) {
                $inner->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.sku', 'like', "%{$search}%")
                    ->orWhere('products.slug', 'like', "%{$search}%")
                    ->orWhere('products.short_description', 'like', "%{$search}%")
                    ->orWhere('products.description', 'like', "%{$search}%");
            });
        });

        if ($rating = $request->query('rating')) {
            $query->whereIn('products.id', function ($sub) use ($rating) {
                $sub->from('product_reviews')
                    ->select('product_id')
                    ->where('status', Status::REVIEW_APPROVED)
                    ->groupBy('product_id')
                    ->havingRaw('AVG(rating) >= ?', [(float) $rating]);
            });
        }

        if ($brands = array_filter((array) $request->query('brand', []))) {
            $query->whereIn('products.brand_id', $brands);
        } elseif ($brandId = $request->query('brand_select')) {
            $query->where('products.brand_id', $brandId);
        }

        foreach (['vehicle_year', 'vehicle_model', 'vehicle_engine', 'vehicle_engine_type'] as $column) {
            $query->when($request->query($column), fn ($q, $value) => $q->where("products.$column", $value));
        }

        if ($request->boolean('in_stock')) {
            $query->where(function ($q) {
                $q->where('products.inventory_type', Status::DONT_TRACK_INVENTORY)
                    ->orWhere('products.stock_quantity', '>', 0)
                    ->orWhere('products.allow_backorder', Status::YES);
            });
        }

        // Products can be purchased from a specific outlet.
        if ($branchId = $request->query('branch_id')) {
            $query->whereHas('branchInventories', fn ($q) => $q
                ->where('branch_id', $branchId)
                ->whereRaw('stock_quantity - reserved_quantity > 0'));
        }

        if ($request->filled('min_price')) {
            $min = (float) $request->query('min_price');
            $max = (float) $request->query('max_price', PHP_INT_MAX);

            $query->where(function ($q) use ($min, $max) {
                $q->whereBetween('products.sale_price', [$min, $max])
                    ->orWhereBetween('products.regular_price', [$min, $max])
                    ->orWhereHas('variations', fn ($v) => $v
                        ->whereBetween('sale_price', [$min, $max])
                        ->orWhereBetween('regular_price', [$min, $max]))
                    ->orWhereHas('groupedProducts.groupedProduct', fn ($gp) => $gp
                        ->whereBetween('sale_price', [$min, $max])
                        ->orWhereBetween('regular_price', [$min, $max]));
            });
        }
    }

    /**
     * The source computed sort prices with a large CASE expression that walks
     * grouped products and variations; the same expression is reproduced here.
     */
    private function applySort(Builder $query, string $sortBy): void
    {
        $priceExpr = "
            CASE
                WHEN (SELECT COUNT(*) FROM product_groupeds pg WHERE pg.product_id = products.id) > 0 THEN (
                    SELECT MIN(
                        CASE
                            WHEN (SELECT COUNT(*) FROM product_variations v WHERE v.product_id = pg.grouped_product_id) > 0 THEN (
                                SELECT MIN(CASE WHEN v2.sale_price > 0 THEN v2.sale_price ELSE v2.regular_price END)
                                FROM product_variations v2 WHERE v2.product_id = pg.grouped_product_id
                            )
                            WHEN pg_product.sale_price > 0 THEN pg_product.sale_price
                            ELSE pg_product.regular_price
                        END
                    )
                    FROM product_groupeds pg
                    INNER JOIN products pg_product ON pg.grouped_product_id = pg_product.id
                    WHERE pg.product_id = products.id
                )
                WHEN (SELECT MIN(CASE WHEN v.sale_price > 0 THEN v.sale_price ELSE v.regular_price END)
                      FROM product_variations v WHERE v.product_id = products.id) IS NOT NULL THEN (
                    SELECT MIN(CASE WHEN v.sale_price > 0 THEN v.sale_price ELSE v.regular_price END)
                    FROM product_variations v WHERE v.product_id = products.id
                )
                WHEN products.sale_price > 0 THEN products.sale_price
                ELSE products.regular_price
            END
        ";

        $discountExpr = "
            CASE
                WHEN (SELECT COUNT(*) FROM product_groupeds pg WHERE pg.product_id = products.id) > 0 THEN (
                    SELECT MAX(
                        CASE
                            WHEN (SELECT COUNT(*) FROM product_variations v WHERE v.product_id = pg.grouped_product_id) > 0 THEN (
                                SELECT MAX(CASE WHEN v2.sale_price > 0 THEN (v2.regular_price - v2.sale_price) ELSE 0 END)
                                FROM product_variations v2 WHERE v2.product_id = pg.grouped_product_id
                            )
                            WHEN pg_product.sale_price > 0 THEN (pg_product.regular_price - pg_product.sale_price)
                            ELSE 0
                        END
                    )
                    FROM product_groupeds pg
                    INNER JOIN products pg_product ON pg.grouped_product_id = pg_product.id
                    WHERE pg.product_id = products.id
                )
                WHEN (SELECT MAX(CASE WHEN v.sale_price > 0 THEN (v.regular_price - v.sale_price) ELSE 0 END)
                      FROM product_variations v WHERE v.product_id = products.id) IS NOT NULL THEN (
                    SELECT MAX(CASE WHEN v.sale_price > 0 THEN (v.regular_price - v.sale_price) ELSE 0 END)
                    FROM product_variations v WHERE v.product_id = products.id
                )
                WHEN products.sale_price > 0 THEN (products.regular_price - products.sale_price)
                ELSE 0
            END
        ";

        match ($sortBy) {
            'best_seller' => $query
                ->withSum(['orderItems as total_sold_quantity' => fn ($q) => $q->whereHas('order', fn ($o) => $o->delivered())], 'quantity')
                ->orderByDesc('total_sold_quantity'),
            'top_rated' => $query->orderByDesc('reviews_avg_rating')->orderByDesc('products.id'),
            'low_to_high' => $query->orderByRaw("$priceExpr ASC"),
            'high_to_low' => $query->orderByRaw("$priceExpr DESC"),
            'discount_low_to_high' => $query->orderByRaw("$discountExpr ASC"),
            'discount_high_to_low' => $query->orderByRaw("$discountExpr DESC"),
            default => $query->orderByDesc('products.id'),
        };
    }

    /** Min/max price across the unfiltered result set, for the price slider. */
    private function priceRange(Builder $query): array
    {
        $base = $query->clone();

        $max = (float) max(
            (clone $base)->max('products.regular_price') ?? 0,
            (float) \App\Models\ProductVariation::whereIn('product_id', (clone $base)->select('products.id'))->max('regular_price'),
        );

        $minRegular = (float) ((clone $base)->min('products.regular_price') ?? 0);
        $minSale = (float) ((clone $base)->where('products.sale_price', '>', 0)->min('products.sale_price') ?? 0);
        $minVariation = (float) (\App\Models\ProductVariation::whereIn('product_id', (clone $base)->select('products.id'))
            ->where('regular_price', '>', 0)->min('regular_price') ?? 0);

        $candidates = array_filter([$minRegular, $minSale, $minVariation], fn ($v) => $v > 0);
        $min = $candidates ? min($candidates) : 0;

        if ($min >= $max) {
            $min = 0;
        }

        return ['min' => round($min, 2), 'max' => round($max ?: 1000, 2)];
    }

    private function mapCategories($categories): array
    {
        return $categories->map(fn ($category) => $this->mapCategory($category))->values()->all();
    }

    private function mapCategory(\App\Models\Category $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'icon' => fileUrl('category', $category->icon),
            'image' => fileUrl('category', $category->image),
            'show_in_navbar' => (bool) $category->show_in_navbar,
            'is_top' => (bool) $category->is_top,
            'is_popular' => (bool) $category->is_popular,
            'products_count' => $category->products_count ?? null,
            'meta' => [
                'title' => $category->meta_title,
                'description' => $category->meta_description,
                'keywords' => $category->meta_keywords,
            ],
            'subcategories' => $category->relationLoaded('subcategories')
                ? $this->mapCategories($category->subcategories)
                : [],
        ];
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more' => $paginator->hasMorePages(),
        ];
    }
}
