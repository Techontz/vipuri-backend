<?php

namespace App\Models;

use App\Constants\Status;
use App\Traits\GlobalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Product extends Model
{
    use GlobalStatus;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'specifications' => 'object',
            'status' => 'boolean',
            'is_featured' => 'boolean',
            'show_tax' => 'boolean',
            'sale_is_scheduled' => 'boolean',
            'display_available' => 'boolean',
            'display_stock_quantity' => 'boolean',
            'allow_backorder' => 'boolean',
            'collection_one' => 'boolean',
            'collection_two' => 'boolean',
            'show_deals' => 'boolean',
            'limited_stock' => 'boolean',
            'schedule_sale_start' => 'datetime',
            'schedule_sale_end' => 'datetime',
            'regular_price' => 'float',
            'sale_price' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Product $product) {
            $expectedSlug = Str::slug($product->name) . '-' . $product->id;

            if ($product->slug !== $expectedSlug) {
                $product->updateQuietly(['slug' => $expectedSlug]);
            }
        });
    }

    /* ------------------------------------------------------------------ *
     | Relationships
     * ------------------------------------------------------------------ */

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_class');
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    public function shippingClassModel(): BelongsTo
    {
        return $this->belongsTo(ShippingClass::class, 'shipping_class');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->where('variation_id', 0);
    }

    public function allMedia(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'product_attributes');
    }

    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class);
    }

    public function groupedProducts(): HasMany
    {
        return $this->hasMany(ProductGrouped::class, 'product_id');
    }

    public function productUpSells(): HasMany
    {
        return $this->hasMany(ProductUpSell::class, 'product_id')->with('linkedProduct');
    }

    public function productCrossSells(): HasMany
    {
        return $this->hasMany(ProductCrossSell::class, 'product_id')->with('linkedProduct');
    }

    public function downloadableFiles(): HasMany
    {
        return $this->hasMany(ProductDownloadableFile::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(ProductReview::class)->where('user_id', auth('user')->id() ?? 0);
    }

    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(Offer::class, 'offer_product');
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_product');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function branchInventories(): HasMany
    {
        return $this->hasMany(BranchInventory::class);
    }

    public function stockLogs(): HasMany
    {
        return $this->hasMany(StockLog::class);
    }

    /* ------------------------------------------------------------------ *
     | Scopes
     * ------------------------------------------------------------------ */

    public function scopeSimple($query)
    {
        return $query->where('product_type', Status::PRODUCT_SIMPLE);
    }

    public function scopeVariable($query)
    {
        return $query->where('product_type', Status::PRODUCT_VARIABLE);
    }

    public function scopeGrouped($query)
    {
        return $query->where('product_type', Status::PRODUCT_GROUPED);
    }

    public function scopeExternal($query)
    {
        return $query->where('product_type', Status::PRODUCT_EXTERNAL);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', Status::YES);
    }

    public function scopeDeals($query)
    {
        return $query->where('show_deals', Status::YES);
    }

    public function scopeLimitedStock($query)
    {
        return $query->where('limited_stock', Status::YES);
    }

    public function scopeFlashSell($query)
    {
        return $query->where('sale_is_scheduled', Status::YES)
            ->where('schedule_sale_end', '>', now());
    }

    /**
     * Storefront visibility, ported from the source system: enabled, in an
     * enabled category (or uncategorised), and not auto-unpublished by the
     * low-stock rule.
     */
    public function scopeActive($query)
    {
        $query->where('products.status', Status::ENABLE)->where(function ($q) {
            $q->whereDoesntHave('categories')
                ->orWhereHas('categories', fn ($cat) => $cat->where('categories.status', Status::ENABLE));
        });

        $query->where(function ($q) {
            $q->where('products.inventory_type', Status::DONT_TRACK_INVENTORY)
                ->orWhere(function ($q2) {
                    $q2->where('products.inventory_type', Status::TRACK_INVENTORY)
                        ->where(function ($q3) {
                            $q3->where('products.low_stock_activity', '!=', Status::LOW_STOCK_UNPUBLISH_PRODUCT)
                                ->orWhere(function ($q4) {
                                    $q4->where('products.low_stock_activity', Status::LOW_STOCK_UNPUBLISH_PRODUCT)
                                        ->whereColumn('products.stock_quantity', '>', 'products.min_stock_quantity');
                                });
                        });
                });
        });

        return $query;
    }

    /* ------------------------------------------------------------------ *
     | Accessors / helpers
     * ------------------------------------------------------------------ */

    public function getMainImageAttribute(): ?string
    {
        return $this->media->firstWhere('is_main', true)?->path
            ?? $this->media->first()?->path;
    }

    public function isSimple(): bool
    {
        return $this->product_type === Status::PRODUCT_SIMPLE;
    }

    public function isVariable(): bool
    {
        return $this->product_type === Status::PRODUCT_VARIABLE;
    }

    public function isGrouped(): bool
    {
        return $this->product_type === Status::PRODUCT_GROUPED;
    }

    public function isExternal(): bool
    {
        return $this->product_type === Status::PRODUCT_EXTERNAL;
    }

    public function trackInventory(): bool
    {
        return (int) $this->inventory_type === Status::TRACK_INVENTORY;
    }

    public function isOnSale(): bool
    {
        if (! $this->sale_price || $this->sale_price <= 0) {
            return false;
        }

        if (! $this->schedule_sale_start) {
            return true;
        }

        if (now()->lessThan($this->schedule_sale_start)) {
            return false;
        }

        return ! $this->schedule_sale_end || now()->lessThanOrEqualTo($this->schedule_sale_end);
    }

    public function isOnOffer(): bool
    {
        return $this->offers()->running()->exists();
    }

    /**
     * Effective unit price: scheduled sale > running offer > running campaign >
     * plain sale price > regular price. Ported verbatim from the source.
     */
    public function getPriceAttribute(): float
    {
        $now = now();

        if (
            $this->sale_is_scheduled
            && $this->schedule_sale_start
            && $this->schedule_sale_end
            && $now->between($this->schedule_sale_start, $this->schedule_sale_end)
        ) {
            return (float) $this->sale_price;
        }

        $offer = $this->relationLoaded('offers')
            ? $this->offers->first(fn ($offer) => $offer->isRunning())
            : $this->offers()->running()->orderBy('priority')->first();

        if ($offer) {
            return $offer->applyTo((float) $this->regular_price);
        }

        $campaign = $this->relationLoaded('campaigns')
            ? $this->campaigns->first(fn ($campaign) => $campaign->isRunning())
            : $this->campaigns()->running()->orderBy('start_at')->first();

        if ($campaign) {
            return $campaign->applyTo((float) $this->regular_price);
        }

        if ($this->isOnSale()) {
            return (float) $this->sale_price;
        }

        return (float) $this->regular_price;
    }

    /** Lowest sellable price across variations / grouped children. */
    public function getDisplayPriceAttribute(): float
    {
        if ($this->isVariable() && $this->variations->isNotEmpty()) {
            return (float) $this->variations->min(fn ($v) => $v->price);
        }

        if ($this->isGrouped() && $this->groupedProducts->isNotEmpty()) {
            $prices = $this->groupedProducts
                ->map(fn ($g) => $g->groupedProduct?->display_price)
                ->filter()
                ->all();

            return $prices ? (float) min($prices) : $this->price;
        }

        return $this->price;
    }

    public function getAvgRatingCalcAttribute(): float
    {
        return (float) ($this->reviews()->approved()->avg('rating') ?? 0);
    }

    public function getTotalReviewCalcAttribute(): int
    {
        return (int) $this->reviews()->approved()->count();
    }

    /**
     * Buyable when inventory is untracked, backorders are allowed, or stock is
     * above the low-stock threshold when that threshold disables the button.
     */
    public function isBuyable(): bool
    {
        if (! $this->trackInventory()) {
            return true;
        }

        if ($this->allow_backorder) {
            return true;
        }

        if ((int) $this->low_stock_activity !== Status::LOW_STOCK_NOTHING) {
            return $this->stock_quantity > $this->min_stock_quantity;
        }

        return $this->stock_quantity > 0;
    }

    /** Stock available at a specific branch (falls back to company total). */
    public function stockAtBranch(?int $branchId, int $variationId = 0): int
    {
        if (! $branchId) {
            return (int) $this->stock_quantity;
        }

        return (int) BranchInventory::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $this->id)
            ->where('variation_id', $variationId)
            ->value('stock_quantity');
    }

    public function showCategories(?int $limit = null): string
    {
        if ($this->categories->isEmpty()) {
            return 'N/A';
        }

        return $this->categories->take($limit)->pluck('name')->implode(', ');
    }
}
