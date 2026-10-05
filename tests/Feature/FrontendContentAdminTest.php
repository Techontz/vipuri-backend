<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Models\Frontend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Home-page content edited through the admin API must reach the storefront.
 *
 * Three faults stopped it: the controller json_encode()d values the model's
 * object cast encodes again, so a section decoded to a string; uploads were
 * stored flat in the frontend directory while CmsContent looked only in the
 * section's sub-folder, so every new image resolved to null; and every upload
 * was cropped to 800x600, which cut a 1920x700 banner apart.
 */
class FrontendContentAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        Storage::fake('public');
    }

    public function test_a_saved_banner_slide_reaches_the_home_page_with_its_image(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        Frontend::where('data_keys', 'banner.element')->delete();

        $this->withHeaders($this->adminHeaders($admin))
            ->post('/api/v1/admin/frontend/sections/banner/element', [
                'values' => ['heading' => 'Genuine tyres', 'button_text' => 'Shop tyres'],
                'images' => ['image' => UploadedFile::fake()->image('hero.jpg', 1920, 700)],
            ])
            ->assertOk();

        $row = Frontend::where('data_keys', 'banner.element')->sole();
        $this->assertIsObject($row->data_values, 'data_values was stored double-encoded');
        $this->assertSame('Genuine tyres', $row->data_values->heading);

        [$width, $height] = getimagesize(Storage::disk('public')->path('assets/images/frontend/' . $row->data_values->image));
        $this->assertSame([1920, 700], [$width, $height], 'the banner was cropped');

        $slide = collect($this->get('/api/v1/home')->assertOk()->json('data.sections')['banner.element'] ?? [])->first();
        $this->assertSame('Genuine tyres', $slide['heading'] ?? null);
        $this->assertNotNull($slide['image'] ?? null, 'the uploaded image did not resolve to a URL');
    }

    public function test_saving_section_content_merges_into_an_object(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($admin))
            ->post('/api/v1/admin/frontend/sections/cta/content', ['values' => ['title' => 'Visit a branch']])
            ->assertOk();

        $values = Frontend::where('data_keys', 'cta.content')->sole()->data_values;
        $this->assertIsObject($values);
        $this->assertSame('Visit a branch', $values->title);
    }
}
