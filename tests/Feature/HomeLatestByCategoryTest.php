<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home page's "Latest products" department tabs.
 *
 * Top-level categories only; a product filed under a subcategory counts for
 * its department; departments with products lead (most recently changed
 * first) and empty departments fill the remaining slots in admin order.
 */
class HomeLatestByCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    private function category(string $name, int $position, ?Category $parent = null): Category
    {
        return Category::create([
            'name' => $name, 'slug' => str($name)->slug() . '-' . uniqid(), 'status' => 1,
            'position' => $position, 'parent_id' => $parent?->id,
        ]);
    }

    public function test_departments_with_products_lead_and_empty_ones_fill_the_menu(): void
    {
        $engine = $this->category('Engine', 1);
        $brakes = $this->category('Brakes', 2);
        $wheels = $this->category('Wheels', 3);
        $tyres = $this->category('Tyres', 1, $wheels);
        foreach (range(4, 9) as $i) {
            $this->category("Empty $i", $i);
        }

        $branch = Branch::first();
        $old = $this->makeProduct($branch, attributes: ['name' => 'Old pad']);
        $old->categories()->sync([$brakes->id]);
        $old->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();

        $tyre = $this->makeProduct($branch, attributes: ['name' => 'New tyre']);
        $tyre->categories()->sync([$tyres->id]);   // filed under the subcategory only

        $tabs = collect($this->get('/api/v1/home')->assertOk()->json('data.latest_by_category'));

        $this->assertCount(6, $tabs);
        $this->assertSame(['Wheels', 'Brakes', 'Engine'], $tabs->take(3)->pluck('name')->all());
        $this->assertSame(['New tyre'], collect($tabs[0]['products'])->pluck('name')->all());
        $this->assertSame([], $tabs[2]['products']);
        $this->assertNotContains('Tyres', $tabs->pluck('name'), 'subcategories are not departments');
    }
}
