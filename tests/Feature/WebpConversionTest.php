<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Services\FileManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Uploaded images are stored as WebP.
 *
 * The assertions read the bytes back off the disk rather than trusting the
 * filename: a `.webp` extension on a JPEG body is exactly the failure this is
 * here to catch, so every check either looks for the RIFF/WEBP magic or asks
 * `getimagesizefromstring()` what the file actually is.
 */
class WebpConversionTest extends TestCase
{
    use RefreshDatabase;

    private FileManager $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
        Storage::fake('public');
        $this->files = app(FileManager::class);
    }

    /** The raw bytes of a stored file. */
    private function bytes(string $pathKey, string $filename): string
    {
        $path = getFilePath($pathKey) . '/' . $filename;

        $this->assertTrue(Storage::disk('public')->exists($path), "nothing was stored at {$path}");

        return Storage::disk('public')->get($path);
    }

    /** What the file really is, read from its own header. */
    private function actualType(string $binary): ?int
    {
        $info = @getimagesizefromstring($binary);

        return $info === false ? null : $info[2];
    }

    // ------------------------------------------------------------ conversion

    public function test_a_jpeg_upload_is_stored_as_a_real_webp_file(): void
    {
        $filename = $this->files->uploadImage(
            UploadedFile::fake()->image('part-photo.jpg', 1200, 900),
            'product',
        );

        $this->assertStringEndsWith('.webp', $filename, 'the stored file is not named .webp');

        $binary = $this->bytes('product', $filename);

        // The container itself, not the name we gave it.
        $this->assertSame('RIFF', substr($binary, 0, 4), 'no RIFF header — this is not a WebP container');
        $this->assertSame('WEBP', substr($binary, 8, 4), 'the RIFF container is not WEBP');
        $this->assertSame(IMAGETYPE_WEBP, $this->actualType($binary), 'GD does not recognise the stored file as WebP');
    }

    public function test_a_png_upload_is_stored_as_a_real_webp_file(): void
    {
        $filename = $this->files->uploadImage(
            UploadedFile::fake()->image('diagram.png', 800, 800),
            'category',
        );

        $this->assertStringEndsWith('.webp', $filename);
        $this->assertSame(IMAGETYPE_WEBP, $this->actualType($this->bytes('category', $filename)));
    }

    public function test_the_thumbnail_is_webp_too(): void
    {
        $filename = $this->files->uploadImage(
            UploadedFile::fake()->image('part-photo.jpg', 1200, 900),
            'product',
            withThumb: true,
        );

        $thumb = $this->bytes('product', 'thumb_' . $filename);

        $this->assertSame(IMAGETYPE_WEBP, $this->actualType($thumb));
        $this->assertSame([350, 350], array_slice(getimagesizefromstring($thumb), 0, 2), 'the thumbnail is not the configured size');
    }

    /** The point of the exercise: the WebP has to actually be smaller. */
    public function test_the_stored_webp_is_smaller_than_the_png_it_came_from(): void
    {
        $source = UploadedFile::fake()->image('large.png', 1600, 1600);
        $originalBytes = filesize($source->getRealPath());

        $filename = $this->files->uploadImage($source, 'product');
        $storedBytes = strlen($this->bytes('product', $filename));

        $this->assertLessThan(
            $originalBytes,
            $storedBytes,
            "webp ({$storedBytes}B) is not smaller than the source ({$originalBytes}B)",
        );
    }

    // -------------------------------------------------------------- opt-outs

    /**
     * GD reads only the first frame of an animated GIF, so converting one
     * would silently turn an animation into a still.
     */
    public function test_a_gif_is_left_as_a_gif(): void
    {
        $filename = $this->files->uploadImage(
            UploadedFile::fake()->image('spinner.gif', 400, 400),
            'product',
        );

        $this->assertStringEndsWith('.gif', $filename);
        $this->assertSame(IMAGETYPE_GIF, $this->actualType($this->bytes('product', $filename)));
    }

    /** The favicon lives under this key, and a WebP favicon is a gamble. */
    public function test_a_path_key_marked_preserve_format_keeps_its_original_type(): void
    {
        $filename = $this->files->uploadImage(
            UploadedFile::fake()->image('favicon.png', 64, 64),
            'logoIcon',
        );

        $this->assertStringEndsWith('.png', $filename);
        $this->assertSame(IMAGETYPE_PNG, $this->actualType($this->bytes('logoIcon', $filename)));
    }

    // -------------------------------------------------------------- security

    public function test_a_php_payload_named_as_an_image_is_refused(): void
    {
        $payload = UploadedFile::fake()->createWithContent('shell.jpg', "<?php system(\$_GET['c']); ?>");

        $this->expectException(RuntimeException::class);

        $this->files->uploadImage($payload, 'product');
    }

    public function test_the_uploaded_filename_is_discarded_entirely(): void
    {
        $filename = $this->files->uploadImage(
            UploadedFile::fake()->image('../../../etc/passwd.jpg', 400, 400),
            'product',
        );

        // A UUID and nothing else: no traversal, no attacker-chosen name.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.webp$/',
            $filename,
        );
        $this->assertStringNotContainsString('..', $filename);
        $this->assertStringNotContainsString('/', $filename);
    }

    public function test_an_image_with_too_many_pixels_is_refused_before_it_is_decoded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too many pixels');

        $this->files->uploadImage(UploadedFile::fake()->image('huge.jpg', 9000, 9000), 'product');
    }

    public function test_a_file_that_is_not_an_image_at_all_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->files->uploadImage(
            UploadedFile::fake()->createWithContent('notes.jpg', str_repeat('not an image', 100)),
            'product',
        );
    }

    // ------------------------------------------------------------- geometry

    public function test_a_landscape_photo_is_not_rotated_on_the_way_in(): void
    {
        // `orient()` reads EXIF; an image with no tag must come out unchanged.
        $filename = $this->files->uploadImage(
            UploadedFile::fake()->image('wide.jpg', 1600, 400),
            'brand',
        );

        // The brand key is configured 270x160, so it is covered to that.
        $size = getimagesizefromstring($this->bytes('brand', $filename));

        $this->assertSame([270, 160], array_slice($size, 0, 2));
    }

    public function test_a_key_with_no_configured_size_keeps_its_proportions(): void
    {
        $filename = $this->files->uploadImage(
            UploadedFile::fake()->image('tall.png', 1000, 3000),
            'logoIcon',
        );

        [$width, $height] = getimagesizefromstring($this->bytes('logoIcon', $filename));

        $this->assertSame(3.0, round($height / $width, 2), 'the aspect ratio was not preserved');
        $this->assertLessThanOrEqual(2400, $height, 'an unsized upload was stored at full height');
    }

    // ------------------------------------------------- through the admin API

    /**
     * The path written to the database has to be the file that exists, or the
     * storefront renders a placeholder for a product that has a photo.
     */
    public function test_a_product_uploaded_through_the_api_stores_a_webp_path_that_resolves(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);
        Branch::first() ?? Branch::factory()->create();

        $response = $this->withHeaders($this->adminHeaders($admin))->post('/api/v1/admin/products', [
            'name' => 'Brake Disc Vented 320mm',
            'product_type' => 'simple',
            'regular_price' => 250000,
            'images' => [UploadedFile::fake()->image('brake-disc.jpg', 1400, 1400)],
            'main_image_index' => 0,
        ]);

        $response->assertOk();

        $product = Product::where('name', 'Brake Disc Vented 320mm')->firstOrFail();
        $media = $product->allMedia()->firstOrFail();

        $this->assertStringEndsWith('.webp', $media->path, 'the database path is not a .webp');
        $this->assertSame(IMAGETYPE_WEBP, $this->actualType($this->bytes('product', $media->path)));
        $this->assertTrue((bool) $media->is_main);

        // fileUrl() returns null when the file is missing, so a URL here means
        // the stored path and the stored file agree.
        $this->assertNotNull(fileUrl('product', $media->path), 'the stored path does not resolve to a file');
    }

    public function test_a_category_image_uploaded_through_the_api_is_webp(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $category = Category::create(['name' => 'Brakes', 'slug' => 'brakes-' . uniqid(), 'status' => 1]);

        $this->withHeaders($this->adminHeaders($admin))->post("/api/v1/admin/categories/{$category->id}", [
            'name' => 'Brakes',
            'image' => UploadedFile::fake()->image('brakes.jpg', 900, 400),
        ])->assertOk();

        $stored = $category->fresh()->image;

        $this->assertStringEndsWith('.webp', $stored);
        $this->assertSame(IMAGETYPE_WEBP, $this->actualType($this->bytes('category', $stored)));
    }
}
