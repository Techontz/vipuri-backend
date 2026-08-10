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

    public function test_image_extension_comes_from_the_sniffed_mime_not_the_filename(): void
    {
        // A genuine PNG, but named as if it were executable.
        $file = UploadedFile::fake()->image('payload.php.png')->mimeType('image/png');

        $stored = $this->files->uploadImage($file, 'adminProfile');

        $this->assertStringEndsWith('.png', $stored);
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
