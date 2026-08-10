<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ShippingClass;
use App\Models\StockUnit;
use App\Models\Tax;
use App\Services\AuditService;
use App\Services\FileManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Categories, brands, attributes, taxes, stock units and shipping classes.
 * These are company-wide catalogue settings, so they are super-admin managed
 * (branch staff read them through the product form).
 */
class TaxonomyController extends Controller
{
    public function __construct(
        private readonly FileManager $files,
        private readonly AuditService $audit,
    ) {}

    /* ----------------------------- Categories ------------------------- */

    public function categories(Request $request)
    {
        $categories = Category::query()
            ->with(['subcategories.subcategories'])
            ->whereNull('parent_id')
            ->withCount('products')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return responseSuccess('categories', 'Categories fetched', [
            'categories' => $this->mapTree($categories),
            'flat' => Category::orderBy('name')->get(['id', 'name', 'parent_id']),
        ]);
    }

    public function saveCategory(Request $request, ?int $id = null)
    {
        $category = $id ? Category::findOrFail($id) : new Category();
        $before = $category->getAttributes();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
            'show_in_navbar' => ['nullable', 'boolean'],
            'is_top' => ['nullable', 'boolean'],
            'is_popular' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            'meta_keywords' => ['nullable', 'string', 'max:1000'],
            'icon' => ['nullable', 'image', 'max:5120'],
            'image' => ['nullable', 'image', 'max:5120'],
            'remove_icon' => ['nullable', 'boolean'],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        // Flags, not columns.
        $removeIcon = (bool) ($data['remove_icon'] ?? false);
        $removeImage = (bool) ($data['remove_image'] ?? false);
        unset($data['remove_icon'], $data['remove_image']);

        if (! empty($data['parent_id']) && (int) $data['parent_id'] === (int) $id) {
            return responseError('invalid_parent', ['A category cannot be its own parent']);
        }

        if ($request->hasFile('icon')) {
            $data['icon'] = $this->files->uploadImage($request->file('icon'), 'category', $category->icon);
        } elseif ($removeIcon && $category->icon) {
            $this->files->removeImage('category', $category->icon);
            $data['icon'] = null;
        }

        if ($request->hasFile('image')) {
            $data['image'] = $this->files->uploadImage($request->file('image'), 'category', $category->image, withThumb: true);
        } elseif ($removeImage && $category->image) {
            $this->files->removeImage('category', $category->image);
            $data['image'] = null;
        }

        $category->fill($data);
        $category->slug = $this->uniqueSlug(Category::class, $data['name'], $category->id);
        $category->save();

        $id
            ? $this->audit->logUpdate('category.updated', $category, $before, "Category {$category->name} updated")
            : $this->audit->log('category.created', $category, description: "Category {$category->name} created");

        return responseSuccess('category_saved', 'Category saved', ['category' => $category->fresh()]);
    }

    public function deleteCategory(int $id)
    {
        $category = Category::withCount('products', 'subcategories')->findOrFail($id);

        if ($category->products_count > 0 || $category->subcategories_count > 0) {
            return responseError('category_in_use', [
                'This category has products or subcategories. Deactivate it instead of deleting.',
            ]);
        }

        $name = $category->name;
        $this->files->removeImage('category', $category->icon);
        $this->files->removeImage('category', $category->image);
        $category->delete();

        $this->audit->log('category.deleted', description: "Category $name deleted");

        return responseSuccess('category_deleted', 'Category deleted');
    }

    public function categoryStatus(int $id)
    {
        $category = Category::findOrFail($id);
        $category->changeStatus();

        return responseSuccess('category_status_changed', 'Category status updated', [
            'status' => (bool) $category->status,
        ]);
    }

    /** Drag-and-drop reordering of the category tree. */
    public function reorderCategories(Request $request)
    {
        $data = $request->validate([
            'positions' => ['required', 'array'],
            'positions.*.id' => ['required', 'integer', 'exists:categories,id'],
            'positions.*.position' => ['required', 'integer', 'min:0'],
            'positions.*.parent_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        foreach ($data['positions'] as $row) {
            Category::where('id', $row['id'])->update([
                'position' => $row['position'],
                'parent_id' => $row['parent_id'] ?? null,
            ]);
        }

        return responseSuccess('categories_reordered', 'Category order updated');
    }

    /* ------------------------------- Brands --------------------------- */

    public function brands(Request $request)
    {
        $brands = Brand::query()
            ->withCount('products')
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->orderBy('name')
            ->paginate(getPaginate(20));

        return responseSuccess('brands', 'Brands fetched', [
            'brands' => collect($brands->items())->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'logo' => fileUrl('brand', $b->logo),
                'is_popular' => (bool) $b->is_popular,
                'status' => (bool) $b->status,
                'products_count' => $b->products_count,
                'seo_content' => $b->seo_content,
            ])->values(),
            'pagination' => [
                'current_page' => $brands->currentPage(),
                'last_page' => $brands->lastPage(),
                'total' => $brands->total(),
            ],
        ]);
    }

    public function saveBrand(Request $request, ?int $id = null)
    {
        $brand = $id ? Brand::findOrFail($id) : new Brand();
        $before = $brand->getAttributes();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'is_popular' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'seo_content' => ['nullable', 'array'],
            'logo' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('logo')) {
            $data['logo'] = $this->files->uploadImage($request->file('logo'), 'brand', $brand->logo);
        }

        if (isset($data['seo_content'])) {
            // Cast to object on the model handles encoding.
        }

        $brand->fill($data);
        $brand->slug = $this->uniqueSlug(Brand::class, $data['name'], $brand->id);
        $brand->save();

        $id
            ? $this->audit->logUpdate('brand.updated', $brand, $before, "Brand {$brand->name} updated")
            : $this->audit->log('brand.created', $brand, description: "Brand {$brand->name} created");

        return responseSuccess('brand_saved', 'Brand saved', ['brand' => $brand->fresh()]);
    }

    public function brandStatus(int $id)
    {
        $brand = Brand::findOrFail($id);
        $brand->changeStatus();

        return responseSuccess('brand_status_changed', 'Brand status updated', ['status' => (bool) $brand->status]);
    }

    public function brandPopularStatus(int $id)
    {
        $brand = Brand::findOrFail($id);
        $brand->is_popular = ! $brand->is_popular;
        $brand->save();

        return responseSuccess('brand_popular_changed', 'Brand updated', ['is_popular' => (bool) $brand->is_popular]);
    }

    /* ----------------------------- Attributes -------------------------- */

    public function attributes()
    {
        return responseSuccess('attributes', 'Attributes fetched', [
            'attributes' => Attribute::with('values')->orderBy('name')->get(),
        ]);
    }

    public function saveAttribute(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'control_type' => ['nullable', 'string', 'max:40'],
            'is_global' => ['nullable', 'boolean'],
            'values' => ['nullable', 'array'],
            'values.*.id' => ['nullable', 'integer'],
            'values.*.name' => ['required_with:values', 'string', 'max:80'],
            'values.*.color_code' => ['nullable', 'string', 'max:40'],
            'values.*.is_pre_selected' => ['nullable', 'boolean'],
        ]);

        $attribute = $id ? Attribute::findOrFail($id) : new Attribute();
        $attribute->fill(collect($data)->only('name', 'control_type', 'is_global')->all())->save();

        $keptIds = [];

        foreach ($data['values'] ?? [] as $value) {
            $row = ! empty($value['id'])
                ? AttributeValue::where('attribute_id', $attribute->id)->find($value['id'])
                : null;

            $row ??= new AttributeValue(['attribute_id' => $attribute->id]);

            $row->fill([
                'attribute_id' => $attribute->id,
                'name' => $value['name'],
                'color_code' => $value['color_code'] ?? null,
                'is_pre_selected' => $value['is_pre_selected'] ?? 0,
            ])->save();

            $keptIds[] = $row->id;
        }

        if (array_key_exists('values', $data)) {
            $attribute->values()->whereNotIn('id', $keptIds ?: [0])->delete();
        }

        $this->audit->log($id ? 'attribute.updated' : 'attribute.created', $attribute, description: "Attribute {$attribute->name} saved");

        return responseSuccess('attribute_saved', 'Attribute saved', [
            'attribute' => $attribute->fresh('values'),
        ]);
    }

    public function deleteAttribute(int $id)
    {
        $attribute = Attribute::findOrFail($id);

        if ($attribute->productAttributes()->exists()) {
            return responseError('attribute_in_use', ['This attribute is used by products and cannot be deleted']);
        }

        $name = $attribute->name;
        $attribute->values()->delete();
        $attribute->delete();

        $this->audit->log('attribute.deleted', description: "Attribute $name deleted");

        return responseSuccess('attribute_deleted', 'Attribute deleted');
    }

    /* ------------------------- Taxes / units / classes ----------------- */

    public function taxes()
    {
        return responseSuccess('taxes', 'Taxes fetched', ['taxes' => Tax::orderBy('name')->get()]);
    }

    public function saveTax(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'boolean'],
        ]);

        $tax = $id ? Tax::findOrFail($id) : new Tax();
        $tax->fill($data)->save();

        return responseSuccess('tax_saved', 'Tax saved', ['tax' => $tax->fresh()]);
    }

    public function taxStatus(int $id)
    {
        $tax = Tax::findOrFail($id);
        $tax->changeStatus();

        return responseSuccess('tax_status_changed', 'Tax status updated', ['status' => (bool) $tax->status]);
    }

    public function stockUnits()
    {
        return responseSuccess('stock_units', 'Stock units fetched', ['stock_units' => StockUnit::orderBy('name')->get()]);
    }

    public function saveStockUnit(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'status' => ['nullable', 'boolean'],
        ]);

        $unit = $id ? StockUnit::findOrFail($id) : new StockUnit();
        $unit->fill($data)->save();

        return responseSuccess('stock_unit_saved', 'Stock unit saved', ['stock_unit' => $unit->fresh()]);
    }

    public function stockUnitStatus(int $id)
    {
        $unit = StockUnit::findOrFail($id);
        $unit->changeStatus();

        return responseSuccess('stock_unit_status_changed', 'Stock unit updated', ['status' => (bool) $unit->status]);
    }

    public function shippingClasses()
    {
        return responseSuccess('shipping_classes', 'Shipping classes fetched', [
            'shipping_classes' => ShippingClass::orderBy('name')->get(),
        ]);
    }

    public function saveShippingClass(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'status' => ['nullable', 'boolean'],
        ]);

        $class = $id ? ShippingClass::findOrFail($id) : new ShippingClass();
        $class->fill($data)->save();

        return responseSuccess('shipping_class_saved', 'Shipping class saved', ['shipping_class' => $class->fresh()]);
    }

    /* ------------------------------ Helpers ---------------------------- */

    private function mapTree($categories): array
    {
        return $categories->map(fn ($category) => [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'position' => $category->position,
            'icon' => fileUrl('category', $category->icon),
            'image' => fileUrl('category', $category->image),
            'show_in_navbar' => (bool) $category->show_in_navbar,
            'is_top' => (bool) $category->is_top,
            'is_popular' => (bool) $category->is_popular,
            'status' => (bool) $category->status,
            'products_count' => $category->products_count ?? null,
            'meta_title' => $category->meta_title,
            'meta_description' => $category->meta_description,
            'meta_keywords' => $category->meta_keywords,
            'subcategories' => $category->relationLoaded('subcategories')
                ? $this->mapTree($category->subcategories)
                : [],
        ])->values()->all();
    }

    private function uniqueSlug(string $model, string $name, ?int $ignoreId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;

        while ($model::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . ++$i;
        }

        return $slug;
    }
}
