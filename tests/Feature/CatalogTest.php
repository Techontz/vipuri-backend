<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->branch = Branch::where('code', 'DSM-01')->firstOrFail();
    }

    private function makeCategory(string $name, ?int $parentId = null): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'parent_id' => $parentId,
            'status' => 1,
            'show_in_navbar' => 1,
        ]);
    }

    public function test_the_storefront_lists_only_published_products(): void
    {
        $this->makeProduct($this->branch, 5, ['name' => 'Visible Part']);
        $this->makeProduct($this->branch, 5, ['name' => 'Hidden Part', 'status' => 0]);

        $response = $this->getJson('/api/v1/products')->assertOk();
        $names = collect($response->json('data.products'))->pluck('name')->all();

        $this->assertContains('Visible Part', $names);
        $this->assertNotContains('Hidden Part', $names);
    }

    public function test_search_matches_name_and_sku(): void
    {
        $this->makeProduct($this->branch, 5, ['name' => 'Brake Pad Set', 'sku' => 'BPS-1']);
        $this->makeProduct($this->branch, 5, ['name' => 'Oil Filter', 'sku' => 'OF-1']);

        $byName = $this->getJson('/api/v1/products?search=Brake')->assertOk();
        $this->assertSame(1, $byName->json('data.pagination.total'));

        $bySku = $this->getJson('/api/v1/products?search=OF-1')->assertOk();
        $this->assertSame(1, $bySku->json('data.pagination.total'));
    }

    public function test_products_can_be_filtered_by_vehicle_fitment(): void
    {
        $this->makeProduct($this->branch, 5, [
            'name' => 'Hilux Filter',
            'vehicle_model' => 'Hilux',
            'vehicle_year' => 2020,
            'vehicle_engine' => '2.4L',
            'vehicle_engine_type' => 'Diesel',
        ]);
        $this->makeProduct($this->branch, 5, ['name' => 'Corolla Filter', 'vehicle_model' => 'Corolla']);

        $response = $this->getJson('/api/v1/products?vehicle_model=Hilux')->assertOk();

        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame('Hilux Filter', $response->json('data.products.0.name'));

        $byYear = $this->getJson('/api/v1/products?vehicle_year=2020')->assertOk();
        $this->assertSame(1, $byYear->json('data.pagination.total'));
    }

    public function test_the_vehicle_filter_endpoint_lists_distinct_options(): void
    {
        $this->makeProduct($this->branch, 5, ['vehicle_model' => 'Hilux', 'vehicle_year' => 2020]);
        $this->makeProduct($this->branch, 5, ['vehicle_model' => 'Hilux', 'vehicle_year' => 2021]);

        $response = $this->getJson('/api/v1/products/vehicle-filters')->assertOk();

        $this->assertSame(['Hilux'], $response->json('data.models'));
        $this->assertCount(2, $response->json('data.years'));
    }

    public function test_products_can_be_filtered_by_category_including_descendants(): void
    {
        $parent = $this->makeCategory('Engine Parts');
        $child = $this->makeCategory('Filters', $parent->id);

        $product = $this->makeProduct($this->branch, 5, ['name' => 'Air Filter']);
        $product->categories()->attach($child->id);

        $other = $this->makeProduct($this->branch, 5, ['name' => 'Brake Disc']);
        $other->categories()->attach($this->makeCategory('Brakes')->id);

        $response = $this->getJson('/api/v1/products?category=engine-parts')->assertOk();

        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame('Air Filter', $response->json('data.products.0.name'));
    }

    public function test_products_can_be_filtered_by_brand(): void
    {
        $brand = Brand::create(['name' => 'Denso', 'slug' => 'denso', 'status' => 1]);
        $this->makeProduct($this->branch, 5, ['name' => 'Denso Part', 'brand_id' => $brand->id]);
        $this->makeProduct($this->branch, 5, ['name' => 'Generic Part']);

        $response = $this->getJson("/api/v1/products?brand_slug=denso")->assertOk();

        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame('Denso Part', $response->json('data.products.0.name'));
    }

    public function test_price_sorting_returns_products_cheapest_first(): void
    {
        $this->makeProduct($this->branch, 5, ['name' => 'Expensive', 'regular_price' => 500000]);
        $this->makeProduct($this->branch, 5, ['name' => 'Cheap', 'regular_price' => 10000]);

        $response = $this->getJson('/api/v1/products?sort_by=low_to_high')->assertOk();

        $this->assertSame('Cheap', $response->json('data.products.0.name'));
    }

    public function test_the_branch_filter_only_returns_products_stocked_there(): void
    {
        $other = Branch::where('code', 'ARU-01')->firstOrFail();

        $this->makeProduct($this->branch, 5, ['name' => 'Dar Only']);
        $this->makeProduct($other, 5, ['name' => 'Arusha Only']);

        $response = $this->getJson("/api/v1/products?branch_id={$this->branch->id}")->assertOk();
        $names = collect($response->json('data.products'))->pluck('name')->all();

        $this->assertContains('Dar Only', $names);
        $this->assertNotContains('Arusha Only', $names);
    }

    public function test_the_product_page_exposes_branch_availability(): void
    {
        $product = $this->makeProduct($this->branch, 7, ['name' => 'Stocked Part']);

        $response = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();
        $availability = $response->json('data.product.branch_availability');

        $this->assertNotEmpty($availability);
        $this->assertSame($this->branch->id, $availability[0]['branch_id']);
        $this->assertSame(7, $availability[0]['quantity']);
    }

    public function test_an_unknown_product_slug_returns_404(): void
    {
        $this->getJson('/api/v1/products/does-not-exist')->assertStatus(404);
    }

    public function test_prices_are_reported_in_whole_shillings(): void
    {
        $product = $this->makeProduct($this->branch, 5, ['regular_price' => 145000, 'sale_price' => 129000]);

        $response = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();

        $this->assertSame(145000.0, (float) $response->json('data.product.regular_price'));
        $this->assertSame(129000.0, (float) $response->json('data.product.price'));
        $this->assertSame(11, $response->json('data.product.discount_percent'));
    }

    public function test_a_worker_without_product_create_permission_cannot_add_a_product(): void
    {
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->branch);

        $this->withHeaders($this->adminHeaders($worker))
            ->postJson('/api/v1/admin/products', ['name' => 'Sneaky', 'product_type' => 'simple'])
            ->assertStatus(403);
    }

    public function test_a_super_admin_can_create_a_product_with_branch_stock(): void
    {
        $super = $this->makeStaff(Roles::SUPER_ADMIN);
        $category = $this->makeCategory('Filters');

        $response = $this->withHeaders($this->adminHeaders($super))
            ->postJson('/api/v1/admin/products', [
                'name' => 'Created Part',
                'product_type' => 'simple',
                'regular_price' => 250000,
                'sale_price' => 0,
                'inventory_type' => 1,
                'category_ids' => [$category->id],
                'sku' => 'CP-1',
                'branch_stock' => [
                    ['branch_id' => $this->branch->id, 'variation_id' => 0, 'stock_quantity' => 12, 'min_stock_quantity' => 2],
                ],
            ])
            ->assertOk();

        $product = Product::find($response->json('data.product_id'));

        $this->assertNotNull($product);
        $this->assertSame('Created Part', $product->name);
        $this->assertSame(12, (int) $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('branch_inventories', [
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'stock_quantity' => 12,
        ]);
    }

    public function test_the_product_slug_is_generated_from_the_name(): void
    {
        $product = $this->makeProduct($this->branch, 1, ['name' => 'Brake Pad Set — Front']);

        $this->assertStringStartsWith('brake-pad-set-front-', $product->fresh()->slug);
    }
}
