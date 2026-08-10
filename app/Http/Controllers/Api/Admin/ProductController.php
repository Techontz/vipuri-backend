<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductDetailResource;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariation;
use App\Models\ShippingClass;
use App\Models\StockUnit;
use App\Models\Tax;
use App\Services\Ai\ProductAiService;
use App\Services\AuditService;
use App\Services\FileManager;
use App\Services\InventoryService;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Product catalogue management. Preserves every field and product type the
 * source system supported: simple, variable, grouped and external.
 */
class ProductController extends Controller
{
    use ScopesToBranch;

    public function __construct(
        private readonly FileManager $files,
        private readonly AuditService $audit,
        private readonly InventoryService $inventory,
        private readonly ProductAiService $ai,
    ) {}

    public function index(Request $request)
    {
        $query = Product::query()
            ->with('brand:id,name', 'media', 'categories:id,name', 'stockUnit:id,name')
            ->withCount('variations')
            ->when($request->query('search'), fn ($q, $s) => $q->where(function ($inner) use ($s) {
                $inner->where('name', 'like', "%$s%")
                    ->orWhere('sku', 'like', "%$s%")
                    ->orWhere('gtin', 'like', "%$s%");
            }))
            ->when($request->query('type'), fn ($q, $type) => $q->where('product_type', $type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->query('brand_id'), fn ($q, $id) => $q->where('brand_id', $id))
            ->when($request->query('category_id'), fn ($q, $id) => $q->whereHas('categories', fn ($c) => $c->where('categories.id', $id)))
            ->when($request->boolean('featured'), fn ($q) => $q->featured())
            ->when($request->boolean('low_stock'), fn ($q) => $q
                ->where('inventory_type', Status::TRACK_INVENTORY)
                ->whereColumn('stock_quantity', '<=', 'min_stock_quantity'))
            ->latest('id');

        if ($request->query('sort') === 'top_selling') {
            $query->withSum(['orderItems as total_sold' => fn ($q) => $q->whereHas('order', fn ($o) => $o->delivered())], 'quantity')
                ->reorder()
                ->orderByDesc('total_sold');
        }

        $products = $query->paginate(getPaginate(20));

        return responseSuccess('products', 'Products fetched', [
            'products' => collect($products->items())->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $product->sku,
                'image' => fileUrl('product', $product->main_image, true),
                'product_type' => $product->product_type,
                'brand' => $product->brand?->name,
                'categories' => $product->categories->pluck('name')->values(),
                'regular_price' => (float) $product->regular_price,
                'sale_price' => (float) $product->sale_price,
                'price' => (float) $product->display_price,
                'stock_quantity' => (int) $product->stock_quantity,
                'track_inventory' => $product->trackInventory(),
                'unit' => $product->stockUnit?->name,
                'variations_count' => $product->variations_count,
                'is_featured' => (bool) $product->is_featured,
                'status' => (bool) $product->status,
                'total_sold' => isset($product->total_sold) ? (int) $product->total_sold : null,
                'created_at' => $product->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /** Reference data the product form needs. */
    public function formData()
    {
        return responseSuccess('product_form_data', 'Form data fetched', [
            'categories' => Category::orderBy('name')->get(['id', 'name', 'parent_id', 'slug']),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'taxes' => Tax::active()->get(['id', 'name', 'rate']),
            'stock_units' => StockUnit::active()->get(['id', 'name']),
            'shipping_classes' => ShippingClass::active()->get(['id', 'name']),
            'attributes' => Attribute::with('values')->orderBy('name')->get(),
            'branches' => \App\Models\Branch::active()->get(['id', 'name', 'code']),
            'product_types' => [
                ['value' => Status::PRODUCT_SIMPLE, 'label' => 'Simple'],
                ['value' => Status::PRODUCT_VARIABLE, 'label' => 'Variable'],
                ['value' => Status::PRODUCT_GROUPED, 'label' => 'Grouped'],
                ['value' => Status::PRODUCT_EXTERNAL, 'label' => 'External'],
            ],
            'low_stock_activities' => [
                ['value' => Status::LOW_STOCK_NOTHING, 'label' => 'Do nothing'],
                ['value' => Status::LOW_STOCK_DISABLE_BUY_BUTTON, 'label' => 'Disable buy button'],
                ['value' => Status::LOW_STOCK_UNPUBLISH_PRODUCT, 'label' => 'Unpublish product'],
            ],
        ]);
    }

    public function show(int $id)
    {
        $product = Product::with([
            'brand', 'categories', 'tax', 'stockUnit', 'allMedia',
            'attributes.values', 'productAttributes', 'variations.media',
            'groupedProducts.groupedProduct', 'productUpSells.linkedProduct',
            'productCrossSells.linkedProduct', 'branchInventories.branch',
        ])->findOrFail($id);

        return responseSuccess('product', 'Product fetched', [
            'product' => new ProductDetailResource($product),
            'raw' => [
                // The public resource exposes the tax's name and rate, not its
                // id, and no stock unit at all — so the edit form had nothing
                // to select with and submitted empty strings for both. `raw`
                // is where the form's own values belong.
                'tax_class' => (int) $product->tax_class,
                'stock_unit_id' => (int) $product->stock_unit_id,
                'category_ids' => $product->categories->pluck('id')->values(),
                'attribute_ids' => $product->attributes->pluck('id')->values(),
                'grouped_ids' => $product->groupedProducts->pluck('grouped_product_id')->values(),
                'up_sell_ids' => $product->productUpSells->pluck('up_sell_product_id')->values(),
                'cross_sell_ids' => $product->productCrossSells->pluck('cross_sell_product_id')->values(),
            ],
            'branch_inventory' => $product->branchInventories->map(fn ($row) => [
                'branch_id' => $row->branch_id,
                'branch_name' => $row->branch?->name,
                'variation_id' => (int) $row->variation_id,
                'stock_quantity' => (int) $row->stock_quantity,
                'reserved_quantity' => (int) $row->reserved_quantity,
                'min_stock_quantity' => (int) $row->min_stock_quantity,
                'shelf_location' => $row->shelf_location,
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        return $this->save($request, null);
    }

    public function update(Request $request, int $id)
    {
        return $this->save($request, Product::findOrFail($id));
    }

    public function changeStatus(int $id)
    {
        $product = Product::findOrFail($id);
        $product->changeStatus();

        $this->audit->log(
            'product.status_changed',
            $product,
            newValues: ['status' => $product->status],
            description: "Product {$product->name} " . ($product->status ? 'published' : 'unpublished'),
        );

        return responseSuccess('product_status_changed', 'Product status updated', [
            'status' => (bool) $product->status,
        ]);
    }

    /** Product search box used by up-sell / cross-sell / grouped pickers. */
    public function search(Request $request)
    {
        $products = Product::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%$s%")->orWhere('sku', 'like', "%$s%"))
            ->when($request->query('exclude'), fn ($q, $id) => $q->where('id', '!=', $id))
            ->with('media')
            ->limit(20)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'price' => (float) $p->display_price,
                'image' => fileUrl('product', $p->main_image, true),
            ]);

        return responseSuccess('product_search', 'Products fetched', ['products' => $products]);
    }

    public function deleteMedia(int $mediaId)
    {
        $media = ProductMedia::findOrFail($mediaId);
        $product = $media->product;
        $wasMain = (bool) $media->is_main;

        $this->files->removeImage('product', $media->path);
        $media->delete();

        // Deleting the main image must not leave the product without one, or
        // every card and listing falls back to the placeholder.
        if ($wasMain && $product) {
            $product->media()->orderBy('id')->first()?->update(['is_main' => 1]);
        }

        return responseSuccess('media_deleted', 'Image removed');
    }

    /**
     * Promote an already-uploaded image to be the product's main one.
     *
     * `main_image_index` on save only covers images being uploaded in that same
     * request, so without this an admin could never change which of the stored
     * images leads the listing without deleting and re-uploading it.
     */
    public function setMainMedia(int $mediaId)
    {
        $media = ProductMedia::findOrFail($mediaId);

        ProductMedia::where('product_id', $media->product_id)->update(['is_main' => 0]);
        $media->update(['is_main' => 1]);

        $this->audit->log(
            'product.main_image_changed',
            $media->product,
            newValues: ['media_id' => $media->id],
            description: 'Main image changed',
        );

        return responseSuccess('media_main', 'Main image updated');
    }

    /** AI-assisted product copy generation. */
    public function aiGenerate(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:191'],
            'category' => ['nullable', 'string', 'max:191'],
            'vehicle_year' => ['nullable', 'string', 'max:10'],
            'vehicle_model' => ['nullable', 'string', 'max:80'],
            'vehicle_engine' => ['nullable', 'string', 'max:80'],
            'vehicle_engine_type' => ['nullable', 'string', 'max:80'],
        ]);

        $result = $this->ai->generateProductContent($data['name'], $data);

        if (($result['status'] ?? 'error') !== 'success') {
            return responseError($result['code'] ?? 'ai_failed', [$result['message'] ?? 'Generation failed']);
        }

        return responseSuccess('ai_generated', 'Content generated', ['content' => $result['content']]);
    }

    /* ------------------------------------------------------------------ *
     | Create / update
     * ------------------------------------------------------------------ */

    private function save(Request $request, ?Product $product)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'product_type' => ['required', Rule::in([
                Status::PRODUCT_SIMPLE, Status::PRODUCT_VARIABLE,
                Status::PRODUCT_GROUPED, Status::PRODUCT_EXTERNAL,
            ])],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string'],
            'specifications' => ['nullable', 'array'],
            'specifications.*.key' => ['nullable', 'string', 'max:191'],
            'specifications.*.value' => ['nullable', 'string', 'max:1000'],

            'regular_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sale_is_scheduled' => ['nullable', 'boolean'],
            'schedule_sale_start' => ['nullable', 'date'],
            'schedule_sale_end' => ['nullable', 'date', 'after_or_equal:schedule_sale_start'],

            'tax_class' => ['nullable', 'integer'],
            'tax_status' => ['nullable', Rule::in(['taxable', 'none'])],
            'show_tax' => ['nullable', 'boolean'],

            'sku' => ['nullable', 'string', 'max:80'],
            'gtin' => ['nullable', 'string', 'max:80'],

            'inventory_type' => ['nullable', 'integer', Rule::in([Status::TRACK_INVENTORY, Status::DONT_TRACK_INVENTORY])],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'stock_unit_id' => ['nullable', 'integer'],
            'display_available' => ['nullable', 'boolean'],
            'display_stock_quantity' => ['nullable', 'boolean'],
            'min_stock_quantity' => ['nullable', 'integer', 'min:0'],
            'low_stock_activity' => ['nullable', 'integer', Rule::in([0, 1, 2])],
            'threshold_quantity' => ['nullable', 'integer', 'min:0'],
            'min_cart_quantity' => ['nullable', 'integer', 'min:0'],
            'max_cart_quantity' => ['nullable', 'integer', 'min:0'],
            'allow_backorder' => ['nullable', 'boolean'],

            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'shipping_class' => ['nullable', 'string', 'max:40'],

            'product_url' => ['nullable', 'url', 'max:255'],
            'button_text' => ['nullable', 'string', 'max:80'],

            'is_featured' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'collection_one' => ['nullable', 'boolean'],
            'collection_two' => ['nullable', 'boolean'],
            'show_deals' => ['nullable', 'boolean'],
            'limited_stock' => ['nullable', 'boolean'],

            'vehicle_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'vehicle_model' => ['nullable', 'string', 'max:80'],
            'vehicle_engine' => ['nullable', 'string', 'max:80'],
            'vehicle_engine_type' => ['nullable', 'string', 'max:80'],

            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            'meta_keywords' => ['nullable', 'string', 'max:1000'],

            'attribute_ids' => ['nullable', 'array'],
            'attribute_ids.*' => ['integer', 'exists:attributes,id'],
            'variations' => ['nullable', 'array'],
            'variations.*.id' => ['nullable', 'integer'],
            'variations.*.attribute_values' => ['required_with:variations', 'array'],
            'variations.*.regular_price' => ['nullable', 'numeric', 'min:0'],
            'variations.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'variations.*.sku' => ['nullable', 'string', 'max:80'],
            'variations.*.stock_quantity' => ['nullable', 'integer', 'min:0'],
            'variations.*.inventory_type' => ['nullable', 'integer'],
            'variations.*.allow_backorder' => ['nullable', 'boolean'],
            'variations.*.min_cart_quantity' => ['nullable', 'integer', 'min:0'],
            'variations.*.max_cart_quantity' => ['nullable', 'integer', 'min:0'],
            'variations.*.min_stock_quantity' => ['nullable', 'integer', 'min:0'],
            'variations.*.low_stock_activity' => ['nullable', 'integer'],

            'grouped_ids' => ['nullable', 'array'],
            'grouped_ids.*' => ['integer', 'exists:products,id'],
            'up_sell_ids' => ['nullable', 'array'],
            'up_sell_ids.*' => ['integer', 'exists:products,id'],
            'cross_sell_ids' => ['nullable', 'array'],
            'cross_sell_ids.*' => ['integer', 'exists:products,id'],

            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['image', 'max:8192'],
            'main_image_index' => ['nullable', 'integer', 'min:0'],

            'branch_stock' => ['nullable', 'array'],
            'branch_stock.*.branch_id' => ['required_with:branch_stock', 'integer', 'exists:branches,id'],
            'branch_stock.*.variation_id' => ['nullable', 'integer'],
            'branch_stock.*.stock_quantity' => ['required_with:branch_stock', 'integer', 'min:0'],
            'branch_stock.*.min_stock_quantity' => ['nullable', 'integer', 'min:0'],
            'branch_stock.*.shelf_location' => ['nullable', 'string', 'max:80'],
        ]);

        $isNew = $product === null;
        $before = $product?->getAttributes() ?? [];

        $product = DB::transaction(function () use ($data, $request, $product) {
            $attributes = collect($data)->only([
                'name', 'product_type', 'brand_id', 'description', 'short_description',
                'regular_price', 'sale_price', 'sale_is_scheduled', 'schedule_sale_start', 'schedule_sale_end',
                'tax_class', 'tax_status', 'show_tax', 'sku', 'gtin',
                'inventory_type', 'stock_unit_id', 'display_available', 'display_stock_quantity',
                'min_stock_quantity', 'low_stock_activity', 'threshold_quantity',
                'min_cart_quantity', 'max_cart_quantity', 'allow_backorder',
                'weight', 'length', 'width', 'height', 'shipping_class',
                'product_url', 'button_text', 'is_featured', 'status',
                'collection_one', 'collection_two', 'show_deals', 'limited_stock',
                'vehicle_year', 'vehicle_model', 'vehicle_engine', 'vehicle_engine_type',
                'meta_title', 'meta_description', 'meta_keywords',
            ])->all();

            $attributes['brand_id'] = $data['brand_id'] ?? 0;

            // `tax_class` and `stock_unit_id` are `unsignedBigInteger(…)
            // ->default(0)` — NOT NULL, and 0 is a real choice the form offers
            // ("No tax", "Choose a unit"). Every control posts as a string, so
            // choosing those options sends "", which ConvertEmptyStringsToNull
            // turns into null before validation; writing that null is what
            // raised "Column 'tax_class' cannot be null".
            //
            // Present-but-empty therefore means 0, the option the admin picked.
            // A key that is absent is left out of $attributes entirely, so a
            // partial update keeps whatever is already stored rather than
            // silently clearing it.
            foreach (['tax_class', 'stock_unit_id'] as $column) {
                if (array_key_exists($column, $attributes)) {
                    $attributes[$column] = (int) ($attributes[$column] ?? 0);
                }
            }

            $attributes['company_id'] = Company::current()->id;

            if (array_key_exists('specifications', $data)) {
                $attributes['specifications'] = [
                    'key' => array_values(array_map(fn ($s) => $s['key'] ?? '', $data['specifications'] ?? [])),
                    'value' => array_values(array_map(fn ($s) => $s['value'] ?? '', $data['specifications'] ?? [])),
                ];
            }

            // Simple products carry their own stock; the rest carry it per variation.
            if (($data['product_type'] ?? null) === Status::PRODUCT_SIMPLE && array_key_exists('stock_quantity', $data)) {
                $attributes['stock_quantity'] = $data['stock_quantity'];
            }

            if ($product) {
                $product->fill($attributes)->save();
            } else {
                $product = Product::create($attributes);
            }

            $product->categories()->sync($data['category_ids'] ?? []);

            $this->syncAttributes($product, $data['attribute_ids'] ?? []);
            $this->syncVariations($product, $data['variations'] ?? []);
            $this->syncLinkedProducts($product, $data);
            $this->syncImages($product, $request, $data['main_image_index'] ?? null);

            if (! empty($data['branch_stock'])) {
                $this->syncBranchStock($product, $data['branch_stock']);
            }

            return $product;
        });

        $product->refresh();

        $isNew
            ? $this->audit->log('product.created', $product, newValues: ['name' => $product->name], description: "Product {$product->name} created")
            : $this->audit->logUpdate('product.updated', $product, $before, "Product {$product->name} updated");

        return responseSuccess(
            $isNew ? 'product_created' : 'product_updated',
            $isNew ? 'Product created' : 'Product updated',
            ['product_id' => $product->id, 'slug' => $product->slug],
        );
    }

    private function syncAttributes(Product $product, array $attributeIds): void
    {
        $product->productAttributes()->whereNotIn('attribute_id', $attributeIds ?: [0])->delete();

        foreach ($attributeIds as $attributeId) {
            $product->productAttributes()->firstOrCreate(
                ['attribute_id' => $attributeId],
                ['is_visible' => 1],
            );
        }
    }

    private function syncVariations(Product $product, array $variations): void
    {
        if (! $product->isVariable()) {
            if ($product->variations()->exists()) {
                $product->variations()->delete();
            }

            return;
        }

        $keptIds = [];

        foreach ($variations as $payload) {
            $values = array_values(array_map('intval', $payload['attribute_values'] ?? []));
            sort($values);

            $variation = ! empty($payload['id'])
                ? ProductVariation::where('product_id', $product->id)->find($payload['id'])
                : null;

            $variation ??= new ProductVariation(['product_id' => $product->id]);

            $variation->fill([
                'product_id' => $product->id,
                'attribute_values' => $values,
                'regular_price' => $payload['regular_price'] ?? 0,
                'sale_price' => $payload['sale_price'] ?? 0,
                'sku' => $payload['sku'] ?? null,
                'stock_quantity' => $payload['stock_quantity'] ?? 0,
                'inventory_type' => $payload['inventory_type'] ?? $product->inventory_type,
                'allow_backorder' => $payload['allow_backorder'] ?? 0,
                'min_cart_quantity' => $payload['min_cart_quantity'] ?? 0,
                'max_cart_quantity' => $payload['max_cart_quantity'] ?? 0,
                'min_stock_quantity' => $payload['min_stock_quantity'] ?? 0,
                'low_stock_activity' => $payload['low_stock_activity'] ?? 0,
                'stock_unit_id' => $product->stock_unit_id,
            ])->save();

            $keptIds[] = $variation->id;
        }

        $product->variations()->whereNotIn('id', $keptIds ?: [0])->delete();
    }

    private function syncLinkedProducts(Product $product, array $data): void
    {
        if (array_key_exists('grouped_ids', $data)) {
            $product->groupedProducts()->delete();

            foreach (array_unique($data['grouped_ids'] ?? []) as $groupedId) {
                if ((int) $groupedId !== $product->id) {
                    $product->groupedProducts()->create(['grouped_product_id' => $groupedId]);
                }
            }
        }

        if (array_key_exists('up_sell_ids', $data)) {
            $product->productUpSells()->delete();

            foreach (array_unique($data['up_sell_ids'] ?? []) as $id) {
                if ((int) $id !== $product->id) {
                    $product->productUpSells()->create(['up_sell_product_id' => $id]);
                }
            }
        }

        if (array_key_exists('cross_sell_ids', $data)) {
            $product->productCrossSells()->delete();

            foreach (array_unique($data['cross_sell_ids'] ?? []) as $id) {
                if ((int) $id !== $product->id) {
                    $product->productCrossSells()->create(['cross_sell_product_id' => $id]);
                }
            }
        }
    }

    private function syncImages(Product $product, Request $request, ?int $mainIndex): void
    {
        $files = $request->file('images', []);

        if (empty($files)) {
            return;
        }

        $hasMain = $product->media()->where('is_main', 1)->exists();

        foreach ($files as $index => $file) {
            $filename = $this->files->uploadImage($file, 'product', withThumb: true);

            $isMain = $mainIndex !== null
                ? $index === $mainIndex
                : (! $hasMain && $index === 0);

            if ($isMain) {
                $product->media()->update(['is_main' => 0]);
            }

            $product->allMedia()->create([
                'path' => $filename,
                'is_main' => $isMain,
                'variation_id' => 0,
            ]);
        }

        // Guarantee exactly one main image.
        if (! $product->media()->where('is_main', 1)->exists()) {
            $product->media()->orderBy('id')->first()?->update(['is_main' => 1]);
        }
    }

    private function syncBranchStock(Product $product, array $rows): void
    {
        foreach ($rows as $row) {
            $branchId = (int) $row['branch_id'];

            // A branch manager may only set stock for their own branch.
            $this->authorizeBranch($branchId);

            $this->inventory->setQuantity(
                $branchId,
                $product->id,
                (int) ($row['variation_id'] ?? 0),
                (int) $row['stock_quantity'],
                'Set from the product form',
            );

            $inventoryRow = $this->inventory->row($branchId, $product->id, (int) ($row['variation_id'] ?? 0));
            $inventoryRow->min_stock_quantity = (int) ($row['min_stock_quantity'] ?? 0);
            $inventoryRow->shelf_location = $row['shelf_location'] ?? null;
            $inventoryRow->save();
        }
    }
}
