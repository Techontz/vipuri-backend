<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The admin category screen's contract with its API.
 *
 * The edit modal broke because the listing payload and the frontend type
 * disagreed about shape: `mapTree()` sends `meta_title` flat, while the shared
 * `CategoryNode` declared a nested `meta` object, so reading `node.meta.title`
 * threw before the modal could open. `mapTree()` is a hand-rolled array rather
 * than an API Resource, so nothing kept the two in step.
 *
 * These tests pin the payload's shape, and the round-trip of the fields the
 * form was silently resetting — `status` and `position` were hardcoded to
 * `true` and `0`, so saving any category re-enabled it and lost its ordering.
 */
class CategoryAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        Storage::fake('public');
    }

    private function category(array $attributes = []): Category
    {
        return Category::create(array_merge([
            'name' => 'Brakes',
            'slug' => 'brakes-' . uniqid(),
            'status' => 1,
            'position' => 3,
        ], $attributes));
    }

    // ------------------------------------------------------- payload shape

    public function test_the_listing_sends_the_fields_the_edit_form_reads(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $this->category(['name' => 'Brake System', 'meta_title' => 'Brakes | VIPURI']);

        $response = $this->withHeaders($this->adminHeaders($admin))
            ->get('/api/v1/admin/categories')
            ->assertOk();

        $row = collect($response->json('data.categories'))->firstWhere('name', 'Brake System');

        $this->assertNotNull($row, 'the category is missing from the listing');

        // Flat, not nested under `meta` — this is exactly the mismatch that
        // made the edit button appear to do nothing.
        $this->assertArrayHasKey('meta_title', $row);
        $this->assertArrayNotHasKey('meta', $row);
        $this->assertSame('Brakes | VIPURI', $row['meta_title']);

        // The listing needs these to render a thumbnail, a parent column and
        // a truthful status badge.
        foreach (['icon', 'image', 'status', 'position', 'parent_id', 'slug', 'products_count'] as $key) {
            $this->assertArrayHasKey($key, $row, "the listing omits {$key}");
        }
    }

    // ------------------------------------------------------- field round-trip

    public function test_editing_a_disabled_category_does_not_silently_re_enable_it(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $category = $this->category(['status' => 0]);

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            ['name' => $category->name, 'status' => '0'],
        )->assertOk();

        $this->assertSame(0, (int) $category->fresh()->status, 'a disabled category was re-enabled by an edit');
    }

    public function test_editing_preserves_the_categorys_position(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $category = $this->category(['position' => 7]);

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            ['name' => $category->name, 'status' => '1', 'position' => '7'],
        )->assertOk();

        $this->assertSame(7, (int) $category->fresh()->position, 'the ordering was reset by an edit');
    }

    // -------------------------------------------------------------- imagery

    public function test_an_icon_and_a_banner_are_stored_independently(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $category = $this->category();

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            [
                'name' => $category->name,
                'icon' => UploadedFile::fake()->image('icon.png', 200, 200),
                'image' => UploadedFile::fake()->image('banner.jpg', 900, 400),
            ],
        )->assertOk();

        $fresh = $category->fresh();

        $this->assertNotNull($fresh->icon);
        $this->assertNotNull($fresh->image);
        $this->assertNotSame($fresh->icon, $fresh->image, 'icon and banner must be separate files');

        Storage::disk('public')->assertExists(getFilePath('category') . '/' . $fresh->icon);
        Storage::disk('public')->assertExists(getFilePath('category') . '/' . $fresh->image);
    }

    public function test_replacing_only_the_icon_leaves_the_banner_alone(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $category = $this->category();

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            [
                'name' => $category->name,
                'icon' => UploadedFile::fake()->image('icon.png', 200, 200),
                'image' => UploadedFile::fake()->image('banner.jpg', 900, 400),
            ],
        )->assertOk();

        $before = $category->fresh();

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            ['name' => $category->name, 'icon' => UploadedFile::fake()->image('newicon.png', 200, 200)],
        )->assertOk();

        $after = $category->fresh();

        $this->assertNotSame($before->icon, $after->icon, 'the icon was not replaced');
        $this->assertSame($before->image, $after->image, 'replacing the icon disturbed the banner');

        // The superseded icon must not linger.
        Storage::disk('public')->assertMissing(getFilePath('category') . '/' . $before->icon);
    }

    public function test_removing_the_icon_leaves_the_banner_in_place(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $category = $this->category();

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            [
                'name' => $category->name,
                'icon' => UploadedFile::fake()->image('icon.png', 200, 200),
                'image' => UploadedFile::fake()->image('banner.jpg', 900, 400),
            ],
        )->assertOk();

        $stored = $category->fresh();

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            ['name' => $category->name, 'remove_icon' => '1'],
        )->assertOk();

        $after = $category->fresh();

        $this->assertNull($after->icon, 'remove_icon did not clear the column');
        $this->assertSame($stored->image, $after->image, 'removing the icon also removed the banner');
        Storage::disk('public')->assertMissing(getFilePath('category') . '/' . $stored->icon);
        Storage::disk('public')->assertExists(getFilePath('category') . '/' . $after->image);
    }

    public function test_a_saved_image_comes_back_as_a_url_not_a_bare_filename(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $category = $this->category(['name' => 'Headlights']);

        $this->withHeaders($this->adminHeaders($admin))->post(
            "/api/v1/admin/categories/{$category->id}",
            ['name' => 'Headlights', 'image' => UploadedFile::fake()->image('banner.jpg', 900, 400)],
        )->assertOk();

        $response = $this->withHeaders($this->adminHeaders($admin))
            ->get('/api/v1/admin/categories')
            ->assertOk();

        $row = collect($response->json('data.categories'))->firstWhere('name', 'Headlights');

        $this->assertNotNull($row['image']);
        $this->assertStringContainsString(getFilePath('category'), $row['image']);
        $this->assertStringNotContainsString('banner.jpg', $row['image'], 'the client filename must not be stored');
    }

    public function test_a_category_with_no_imagery_reports_null_rather_than_a_broken_url(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        $this->category(['name' => 'Unphotographed']);

        $response = $this->withHeaders($this->adminHeaders($admin))
            ->get('/api/v1/admin/categories')
            ->assertOk();

        $row = collect($response->json('data.categories'))->firstWhere('name', 'Unphotographed');

        $this->assertNull($row['icon']);
        $this->assertNull($row['image']);
    }
}
