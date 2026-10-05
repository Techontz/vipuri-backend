<?php

namespace Tests\Feature;

use App\Services\FileManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Uploads must never let the client choose the stored extension.
 *
 * VIPURI serves uploads from `/media/{path}`, so the name a file lands under is
 * a security boundary, not a cosmetic detail.
 */
class FileUploadTest extends TestCase
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

    /**
     * A GIF is used deliberately. JPEG and PNG are both converted to WebP, so
     * either would come out `.webp` whether the MIME was sniffed or not, and
     * the assertion would prove nothing. GIF is the one allowed type stored in
     * its original format, so it still demonstrates that the extension came
     * from the file's contents rather than from its name.
     */
    public function test_image_extension_comes_from_the_sniffed_mime_not_the_filename(): void
    {
        // A genuine GIF, but named as if it were executable.
        $file = UploadedFile::fake()->image('payload.php.gif')->mimeType('image/gif');

        $stored = $this->files->uploadImage($file, 'adminProfile');

        $this->assertStringEndsWith('.gif', $stored);
        $this->assertStringNotContainsString('.php', $stored);
        $this->assertStringNotContainsString('payload', $stored);
    }

    /** And a convertible type is stored under the extension it was written as. */
    public function test_a_png_is_stored_as_webp_regardless_of_the_uploaded_name(): void
    {
        $file = UploadedFile::fake()->image('payload.php.png')->mimeType('image/png');

        $stored = $this->files->uploadImage($file, 'adminProfile');

        $this->assertStringEndsWith('.webp', $stored);
        $this->assertStringNotContainsString('.php', $stored);
        $this->assertStringNotContainsString('payload', $stored);
    }

    public function test_image_upload_rejects_a_disallowed_mime_type(): void
    {
        $file = UploadedFile::fake()->create('script.svg', 4, 'image/svg+xml');

        $this->expectException(RuntimeException::class);

        $this->files->uploadImage($file, 'adminProfile');
    }

    public function test_attachment_extension_comes_from_the_sniffed_mime(): void
    {
        $file = UploadedFile::fake()->create('invoice.phtml', 10, 'application/pdf');

        $stored = $this->files->uploadFile($file, 'ticket');

        $this->assertStringEndsWith('.pdf', $stored);
        $this->assertStringNotContainsString('phtml', $stored);
    }

    public function test_attachment_upload_rejects_a_disallowed_mime_type(): void
    {
        $file = UploadedFile::fake()->create('shell.php', 4, 'application/x-httpd-php');

        $this->expectException(RuntimeException::class);

        $this->files->uploadFile($file, 'ticket');
    }

    public function test_media_route_refuses_to_escape_the_public_disk(): void
    {
        $this->get('/media/../../../.env')->assertNotFound();
        $this->get('/media/does-not-exist.png')->assertNotFound();
    }
}
