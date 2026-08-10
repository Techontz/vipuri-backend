<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ProductMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The catalogue image pipeline, end to end.
 *
 * Admin multipart upload → FileManager → public disk → database → the URL the
 * storefront and the app actually read. Each step is asserted separately, so a
 * break tells you which one gave way rather than only that a picture is
 * missing.
 */
class CatalogImagePipelineTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        Storage::fake('public');
        $this->branch = Branch::first() ?? Branch::factory()->create();
    }

    public function test_uploading_a_product_image_stores_the_file_and_returns_a_loadable_url(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $product = $this->makeProduct($this->branch);

        $response = $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/products/{$product->id}",
            [
                'name' => $product->name,
                'product_type' => 'simple',
                'price' => 1000,
                'images' => [UploadedFile::fake()->image('part.jpg', 900, 900)],
            ],
        );

        $response->assertOk();

        $media = ProductMedia::where('product_id', $product->id)->latest('id')->first();
        $this->assertNotNull($media, 'no media row was written');

        // The filename is generated, never the client's.
        $this->assertStringNotContainsString('part', $media->path);
        $this->assertStringEndsWith('.jpg', $media->path);

        // The bytes really landed on the public disk.
        Storage::disk('public')->assertExists(getFilePath('product') . '/' . $media->path);

        // And the URL the clients read points at that file.
        //
        // The prefix is not asserted here: Storage::fake swaps in a disk whose
        // url() is root-relative, whereas the real public disk is configured
        // with an absolute one. That difference is the reason both clients
        // resolve relative values rather than assuming absolute — see
        // VpImage.resolve and imageUrl().
        $url = fileUrl('product', $media->path);
        $this->assertNotNull($url);
        $this->assertStringContainsString(getFilePath('product'), $url);
        $this->assertStringContainsString($media->path, $url);
    }

    public function test_a_product_without_an_image_reports_no_url_rather_than_a_broken_one(): void
    {
        $product = $this->makeProduct($this->branch);
        $product->media()->delete();

        $this->assertNull(fileUrl('product', null));
        $this->assertNull(
            fileUrl('product', 'never-uploaded.jpg'),
            'a filename with no file behind it must not produce a URL',
        );
    }

    public function test_deleting_the_main_image_promotes_another_so_the_product_keeps_one(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $product = $this->makeProduct($this->branch);
        $product->media()->delete();

        $main = ProductMedia::create(['product_id' => $product->id, 'path' => 'a.jpg', 'is_main' => 1]);
        $other = ProductMedia::create(['product_id' => $product->id, 'path' => 'b.jpg', 'is_main' => 0]);

        $this->withHeaders($this->adminHeaders($admin))
            ->delete("/api/v1/admin/products/media/{$main->id}")
            ->assertOk();

        $this->assertDatabaseMissing('product_media', ['id' => $main->id]);
        $this->assertEquals(1, $other->fresh()->is_main, 'the surviving image did not become main');
    }

    public function test_an_admin_can_promote_a_stored_image_to_main(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $product = $this->makeProduct($this->branch);
        $product->media()->delete();

        $first = ProductMedia::create(['product_id' => $product->id, 'path' => 'a.jpg', 'is_main' => 1]);
        $second = ProductMedia::create(['product_id' => $product->id, 'path' => 'b.jpg', 'is_main' => 0]);

        $this->withHeaders($this->adminHeaders($admin))
            ->post("/api/v1/admin/products/media/{$second->id}/main")
            ->assertOk();

        $this->assertEquals(0, $first->fresh()->is_main);
        $this->assertEquals(1, $second->fresh()->is_main, 'the chosen image did not become main');
    }

    public function test_uploading_a_category_image_stores_it_and_replacing_it_removes_the_old_file(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $category = Category::first() ?? Category::create(['name' => 'Brakes', 'slug' => 'brakes']);

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            ['name' => $category->name, 'image' => UploadedFile::fake()->image('banner.jpg', 800, 800)],
        )->assertOk();

        $first = $category->fresh()->image;
        $this->assertNotNull($first, 'the category image was not saved');
        Storage::disk('public')->assertExists(getFilePath('category') . '/' . $first);

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            ['name' => $category->name, 'image' => UploadedFile::fake()->image('new.jpg', 800, 800)],
        )->assertOk();

        $second = $category->fresh()->image;
        $this->assertNotEquals($first, $second);

        // Replacing must not leave the superseded file behind forever.
        Storage::disk('public')->assertMissing(getFilePath('category') . '/' . $first);
        Storage::disk('public')->assertExists(getFilePath('category') . '/' . $second);
    }

    public function test_an_admin_can_clear_a_category_image(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $category = Category::first() ?? Category::create(['name' => 'Brakes', 'slug' => 'brakes']);

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            ['name' => $category->name, 'image' => UploadedFile::fake()->image('banner.jpg', 800, 800)],
        )->assertOk();

        $stored = $category->fresh()->image;
        $this->assertNotNull($stored);

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            ['name' => $category->name, 'remove_image' => '1'],
        )->assertOk();

        $this->assertNull($category->fresh()->image, 'remove_image did not clear the column');
        Storage::disk('public')->assertMissing(getFilePath('category') . '/' . $stored);
    }

    public function test_a_non_image_is_refused(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $product = $this->makeProduct($this->branch);

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/products/{$product->id}",
            [
                'name' => $product->name,
                'product_type' => 'simple',
                'price' => 1000,
                'images' => [UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf')],
            ],
        )->assertStatus(422);
    }
}
