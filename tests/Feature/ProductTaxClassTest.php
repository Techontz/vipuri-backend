<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Models\Branch;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `tax_class` and `stock_unit_id` must survive an edit.
 *
 * Both are `unsignedBigInteger(...)->default(0)` — NOT NULL, where 0 means
 * "none". The admin form submits every field as a string, so a control the
 * form never populated arrives as "", which Laravel's
 * ConvertEmptyStringsToNull turns into null before validation sees it. The
 * rules say `nullable`, so null passed, and the column rejected it:
 *
 *   SQLSTATE[23000]: Column 'tax_class' cannot be null
 *
 * `brand_id` was already guarded with `?? 0`; these two were not.
 */
class ProductTaxClassTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        $this->branch = Branch::first() ?? Branch::factory()->create();
    }

    /** The payload the admin form sends when a field was never populated. */
    private function payload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'name' => $product->name,
            'product_type' => 'simple',
            'regular_price' => 1000,
            'tax_class' => '',
            'stock_unit_id' => '',
        ], $overrides);
    }

    /**
     * A client that does not mention the field at all must not clear it.
     *
     * This is the guarantee that matters for the reported bug: an edit that is
     * about something else entirely cannot cost the product its tax class.
     */
    public function test_a_payload_that_omits_tax_class_keeps_the_stored_value(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $product = $this->makeProduct($this->branch);
        $product->update(['tax_class' => 1, 'stock_unit_id' => 2]);

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/products/{$product->id}",
            ['name' => $product->name, 'product_type' => 'simple', 'regular_price' => 1000],
        )->assertOk();

        $fresh = $product->fresh();

        $this->assertSame(1, (int) $fresh->tax_class, 'the existing tax class was lost on update');
        $this->assertSame(2, (int) $fresh->stock_unit_id, 'the existing stock unit was lost on update');
    }

    /**
     * "No tax" is a real option in the form, and it posts an empty string.
     * Choosing it deliberately must still be honoured — as 0, never as null.
     */
    public function test_choosing_the_no_tax_option_stores_zero_not_null(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $product = $this->makeProduct($this->branch);
        $product->update(['tax_class' => 1, 'stock_unit_id' => 2]);

        $this->withHeaders($this->adminHeaders($admin))
            ->post("/api/v1/admin/products/{$product->id}", $this->payload($product))
            ->assertOk();

        $fresh = $product->fresh();

        $this->assertNotNull($fresh->tax_class);
        $this->assertSame(0, (int) $fresh->tax_class);
        $this->assertSame(0, (int) $fresh->stock_unit_id);
    }

    public function test_an_empty_tax_class_never_reaches_the_database_as_null(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $product = $this->makeProduct($this->branch);
        $product->update(['tax_class' => 0, 'stock_unit_id' => 0]);

        $this->withHeaders($this->adminHeaders($admin))
            ->post("/api/v1/admin/products/{$product->id}", $this->payload($product))
            ->assertOk();

        $this->assertNotNull($product->fresh()->tax_class);
        $this->assertNotNull($product->fresh()->stock_unit_id);
    }

    public function test_creating_a_product_with_no_tax_class_stores_the_none_value(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $response = $this->withHeaders($this->adminHeaders($admin))->post('/api/v1/admin/products', [
            'name' => 'Wheel Nut Set',
            'product_type' => 'simple',
            'regular_price' => 5000,
            'tax_class' => '',
            'stock_unit_id' => '',
        ]);

        $response->assertOk();

        $created = Product::where('name', 'Wheel Nut Set')->firstOrFail();

        // 0 is the schema's own default, and what "no tax class" means here.
        $this->assertSame(0, (int) $created->tax_class);
        $this->assertSame(0, (int) $created->stock_unit_id);
    }

    public function test_a_chosen_tax_class_is_still_saved(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $product = $this->makeProduct($this->branch);

        $this->withHeaders($this->adminHeaders($admin))
            ->post(
                "/api/v1/admin/products/{$product->id}",
                $this->payload($product, ['tax_class' => 2, 'stock_unit_id' => 3]),
            )
            ->assertOk();

        $this->assertSame(2, (int) $product->fresh()->tax_class);
        $this->assertSame(3, (int) $product->fresh()->stock_unit_id);
    }

    public function test_the_edit_payload_exposes_the_ids_the_form_needs(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $product = $this->makeProduct($this->branch);
        $product->update(['tax_class' => 1, 'stock_unit_id' => 2]);

        $response = $this->withHeaders($this->adminHeaders($admin))
            ->get("/api/v1/admin/products/{$product->id}")
            ->assertOk();

        // Without these the edit form has nothing to populate its selects from,
        // which is how they came back empty and submitted "".
        $response->assertJsonPath('data.raw.tax_class', 1);
        $response->assertJsonPath('data.raw.stock_unit_id', 2);
    }
}
