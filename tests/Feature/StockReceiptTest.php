<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\StockReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Receive stock (goods received notes): branch isolation, ledger, roll-up.
 */
class StockReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Branch $dodoma;

    private Branch $arusha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();

        $this->arusha = Branch::where('code', 'ARU-01')->firstOrFail();
        $this->dodoma = Branch::where('code', 'DOM-01')->first()
            ?? Branch::create([
                'company_id' => Company::current()->id,
                'name' => 'VIPURI Dodoma',
                'code' => 'DOM-01',
                'slug' => 'vipuri-dodoma',
                'status' => 1,
            ]);
    }

    private function stockAt(Product $product, Branch $branch, int $variationId = 0): ?int
    {
        $row = $product->branchInventories()
            ->where('branch_id', $branch->id)
            ->where('variation_id', $variationId)
            ->first();

        return $row ? (int) $row->stock_quantity : null;
    }

    private function receive(array $headers, array $payload)
    {
        return $this->withHeaders($headers)->postJson('/api/v1/admin/inventory/receipts', $payload);
    }

    public function test_a_branch_manager_receives_stock_into_their_own_branch(): void
    {
        $product = $this->makeProduct($this->dodoma, 4);
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->dodoma);

        $response = $this->receive($this->adminHeaders($manager), [
            'supplier_name' => 'Toyota Tsusho',
            'supplier_reference' => 'INV-778',
            'items' => [['product_id' => $product->id, 'quantity' => 6, 'unit_cost' => 45000]],
        ])->assertCreated();

        $reference = $response->json('data.receipt.reference');
        $this->assertMatchesRegularExpression('/^GRN-DOM-\d{6}$/', $reference);
        $this->assertSame($this->dodoma->id, $response->json('data.receipt.branch_id'));
        $this->assertSame(6, $response->json('data.receipt.total_quantity'));
        $this->assertEquals(270000, $response->json('data.receipt.total_cost'));
        $this->assertSame(10, $response->json('data.receipt.items.0.current_stock'));

        $this->assertSame(10, $this->stockAt($product, $this->dodoma));
        $this->assertSame(10, (int) $product->fresh()->stock_quantity, 'catalogue total re-synced');

        $row = $product->branchInventories()->where('branch_id', $this->dodoma->id)->first();
        $this->assertEquals(45000, (float) $row->cost_price);

        $this->assertDatabaseHas('stock_logs', [
            'branch_id' => $this->dodoma->id,
            'product_id' => $product->id,
            'change_quantity' => 6,
            'post_quantity' => 10,
            'remark' => 'stock_receipt',
            'description' => "Received {$reference} from Toyota Tsusho (inv. INV-778)",
            'actor_id' => $manager->id,
        ]);

        $this->assertDatabaseHas('audit_logs', ['event' => 'stock_receipt.created', 'branch_id' => $this->dodoma->id]);
    }

    public function test_branch_staff_without_a_branch_id_still_go_to_their_own_branch(): void
    {
        $product = $this->makeProduct($this->arusha, 0);
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->dodoma);

        $this->receive($this->adminHeaders($manager), [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated();

        // A branch row is created on the first receipt at Dodoma.
        $this->assertSame(3, $this->stockAt($product, $this->dodoma));
        $this->assertSame(0, $this->stockAt($product, $this->arusha));
        $this->assertSame(3, (int) $product->fresh()->stock_quantity);
    }

    public function test_a_branch_manager_cannot_receive_into_another_branch(): void
    {
        $product = $this->makeProduct($this->arusha, 5);
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->dodoma);

        $this->receive($this->adminHeaders($manager), [
            'branch_id' => $this->arusha->id,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertForbidden();

        $this->assertSame(5, $this->stockAt($product, $this->arusha));
        $this->assertSame(0, StockReceipt::count());
    }

    public function test_company_wide_staff_must_choose_a_branch_and_can_use_any(): void
    {
        $product = $this->makeProduct($this->arusha, 1);
        $admin = $this->makeStaff(Roles::ADMIN, $this->arusha);
        $super = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->receive($this->adminHeaders($super), [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertStatus(422)->assertJsonPath('remark', 'validation_error');

        $this->receive($this->adminHeaders($super), [
            'branch_id' => $this->dodoma->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        // An Admin based in Arusha is company-wide, so Dodoma is allowed too.
        $this->receive($this->adminHeaders($admin), [
            'branch_id' => $this->dodoma->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertCreated();

        $this->assertSame(7, $this->stockAt($product, $this->dodoma));
        $this->assertSame(1, $this->stockAt($product, $this->arusha));
        $this->assertSame(8, (int) $product->fresh()->stock_quantity);
    }

    public function test_invalid_lines_are_rejected_and_nothing_is_booked(): void
    {
        $product = $this->makeProduct($this->dodoma, 2);
        $grouped = $this->makeProduct($this->dodoma, 0, ['product_type' => 'grouped']);
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->dodoma);
        $headers = $this->adminHeaders($manager);

        $this->receive($headers, ['items' => [['product_id' => $product->id, 'quantity' => 0]]])
            ->assertStatus(422);

        $this->receive($headers, ['items' => [['product_id' => 999999, 'quantity' => 1]]])
            ->assertStatus(422);

        $this->receive($headers, ['items' => []])->assertStatus(422);

        $this->receive($headers, ['items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => -5]]])
            ->assertStatus(422);

        $this->receive($headers, ['items' => [['product_id' => $grouped->id, 'quantity' => 1]]])
            ->assertStatus(422);

        // One bad line fails the whole receipt.
        $this->receive($headers, ['items' => [
            ['product_id' => $product->id, 'quantity' => 4],
            ['product_id' => $product->id, 'quantity' => -1],
        ]])->assertStatus(422);

        $this->assertSame(2, $this->stockAt($product, $this->dodoma));
        $this->assertSame(0, StockReceipt::count());
    }

    public function test_variations_must_belong_to_the_product(): void
    {
        $variable = $this->makeProduct($this->dodoma, 0, ['product_type' => 'variable']);
        $other = $this->makeProduct($this->dodoma, 0);
        $variation = ProductVariation::create(['product_id' => $variable->id, 'sku' => 'VAR-1', 'inventory_type' => 1]);
        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->dodoma);
        $headers = $this->adminHeaders($manager);

        // A variable product needs a variation…
        $this->receive($headers, ['items' => [['product_id' => $variable->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.variation_id');

        // …and it must be one of its own.
        $this->receive($headers, ['items' => [['product_id' => $other->id, 'variation_id' => $variation->id, 'quantity' => 1]]])
            ->assertStatus(422);

        $this->receive($headers, ['items' => [['product_id' => $variable->id, 'variation_id' => $variation->id, 'quantity' => 4]]])
            ->assertCreated();

        $this->assertSame(4, $this->stockAt($variable, $this->dodoma, $variation->id));
        $this->assertSame(4, (int) $variation->fresh()->stock_quantity);
    }

    public function test_staff_without_the_receive_permission_are_refused(): void
    {
        $product = $this->makeProduct($this->dodoma, 2);
        $worker = $this->makeStaff(Roles::BRANCH_WORKER, $this->dodoma);
        $headers = $this->adminHeaders($worker);

        $this->receive($headers, ['items' => [['product_id' => $product->id, 'quantity' => 3]]])->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/admin/inventory/receipts')->assertForbidden();

        $this->assertSame(2, $this->stockAt($product, $this->dodoma));
    }

    public function test_receipt_numbers_are_unique_and_sequential(): void
    {
        $product = $this->makeProduct($this->dodoma, 0);
        $super = $this->makeStaff(Roles::SUPER_ADMIN);
        $headers = $this->adminHeaders($super);

        $numbers = [];

        foreach ([$this->dodoma, $this->arusha, $this->dodoma] as $branch) {
            $numbers[] = $this->receive($headers, [
                'branch_id' => $branch->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertCreated()->json('data.receipt.reference');
        }

        $this->assertCount(3, array_unique($numbers));
        $this->assertStringStartsWith('GRN-DOM-', $numbers[0]);
        $this->assertStringStartsWith('GRN-ARU-', $numbers[1]);

        $sequence = array_map(fn ($n) => (int) substr($n, -6), $numbers);
        $this->assertSame([$sequence[0], $sequence[0] + 1, $sequence[0] + 2], $sequence);
    }

    public function test_listing_and_detail_are_branch_scoped(): void
    {
        $product = $this->makeProduct($this->dodoma, 0);
        $super = $this->makeStaff(Roles::SUPER_ADMIN);
        $superHeaders = $this->adminHeaders($super);

        $dodomaId = $this->receive($superHeaders, [
            'branch_id' => $this->dodoma->id,
            'supplier_name' => 'Kibo Spares',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 1000], ['product_id' => $product->id, 'quantity' => 3]],
        ])->json('data.receipt.id');

        $arushaId = $this->receive($superHeaders, [
            'branch_id' => $this->arusha->id,
            'supplier_name' => 'Meru Motors',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json('data.receipt.id');

        $manager = $this->makeStaff(Roles::BRANCH_MANAGER, $this->dodoma);
        $headers = $this->adminHeaders($manager);

        $list = $this->withHeaders($headers)->getJson('/api/v1/admin/inventory/receipts')->assertOk();
        $this->assertSame([$dodomaId], array_column($list->json('data.receipts'), 'id'));
        $list->assertJsonPath('data.receipts.0.item_count', 2)
            ->assertJsonPath('data.receipts.0.total_quantity', 5);

        $this->withHeaders($headers)->getJson("/api/v1/admin/inventory/receipts/$dodomaId")
            ->assertOk()
            ->assertJsonCount(2, 'data.receipt.items')
            ->assertJsonPath('data.receipt.items.0.sku', $product->sku);

        $this->withHeaders($headers)->getJson("/api/v1/admin/inventory/receipts/$arushaId")->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/admin/inventory/receipts?branch_id=' . $this->arusha->id)->assertForbidden();

        // Company-wide: everything, filterable by branch and searchable.
        $superHeaders = $this->adminHeaders($super);
        $this->assertCount(2, $this->withHeaders($superHeaders)->getJson('/api/v1/admin/inventory/receipts')->json('data.receipts'));
        $this->assertSame(
            [$arushaId],
            array_column($this->withHeaders($superHeaders)->getJson('/api/v1/admin/inventory/receipts?branch_id=' . $this->arusha->id)->json('data.receipts'), 'id'),
        );
        $this->assertSame(
            [$dodomaId],
            array_column($this->withHeaders($superHeaders)->getJson('/api/v1/admin/inventory/receipts?search=kibo')->json('data.receipts'), 'id'),
        );
    }
}
