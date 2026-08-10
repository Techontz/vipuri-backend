<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Upload, resize and delete media.
 *
 * Most paths live on the `public` disk and are addressed through /media. Path
 * keys flagged `private` in config/vipuri.php live on the `local` disk instead,
 * which has no URL at all — the only way to read one is {@see download()},
 * behind whatever ownership check the caller applies.
 */
class FileManager
{
    /**
     * Allowed image types, mapped to the extension we store them under.
     *
     * The extension is derived from the sniffed MIME type, never from the
     * uploaded filename — so `payload.php` carrying a valid PNG body is stored
     * as `<uuid>.png`.
     */
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** Allowed attachment types, mapped to the extension we store them under. */
    private const ALLOWED_DOC_MIMES = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/zip' => 'zip',
        'text/plain' => 'txt',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** True when this path key must never be reachable over HTTP. */
    public static function isPrivate(string $pathKey): bool
    {
        return (bool) config("vipuri.file_path.$pathKey.private", false);
    }

    /** The disk a path key is stored on. */
    private function disk(string $pathKey): Filesystem
    {
        return Storage::disk(self::isPrivate($pathKey) ? 'local' : 'public');
    }

    /**
     * Store an uploaded image, optionally resized, optionally with a thumbnail.
     *
     * @return string The stored filename (not the full path).
     */
    public function uploadImage(
        UploadedFile $file,
        string $pathKey,
        ?string $oldFilename = null,
        bool $withThumb = false,
    ): string {
        $extension = self::ALLOWED_IMAGE_MIMES[$file->getMimeType()] ?? null;

        if (! $extension) {
            throw new RuntimeException('Only JPG, PNG, GIF or WEBP images are allowed');
        }

        $directory = getFilePath($pathKey);
        $size = getFileSize($pathKey);
        $filename = Str::uuid()->toString() . '.' . $extension;

        Storage::disk('public')->makeDirectory($directory);

        $manager = new ImageManager(new Driver());
        $image = $manager->decodePath($file->getRealPath());

        if ($size) {
            [$width, $height] = array_map('intval', explode('x', $size));
            $image->cover($width, $height);
        }

        Storage::disk('public')->put(
            "$directory/$filename",
            (string) $image->encodeUsingFileExtension($extension, quality: 88),
        );

        if ($withThumb && ($thumbSize = getThumbSize($pathKey))) {
            [$tw, $th] = array_map('intval', explode('x', $thumbSize));
            $thumb = $manager->decodePath($file->getRealPath())->cover($tw, $th);

            Storage::disk('public')->put(
                "$directory/thumb_$filename",
                (string) $thumb->encodeUsingFileExtension($extension, quality: 82),
            );
        }

        if ($oldFilename) {
            $this->removeImage($pathKey, $oldFilename);
        }

        return $filename;
    }

    /** Store a non-image attachment (ticket attachments, downloadable files). */
    public function uploadFile(UploadedFile $file, string $pathKey): string
    {
        $extension = self::ALLOWED_DOC_MIMES[$file->getMimeType()] ?? null;

        if (! $extension) {
            throw new RuntimeException('This file type is not allowed');
        }

        $directory = getFilePath($pathKey);
        $filename = Str::uuid()->toString() . '.' . $extension;

        $this->disk($pathKey)->putFileAs($directory, $file, $filename);

        return $filename;
    }

    /**
     * Stream a stored file as a download.
     *
     * Used for anything that must not be reachable from the public media path.
     * The filename is validated against the disk root so a crafted value cannot
     * escape the directory it belongs to.
     */
    public function download(string $pathKey, string $filename): BinaryFileResponse
    {
        if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            abort(404);
        }

        $path = getFilePath($pathKey) . '/' . $filename;
        $disk = $this->disk($pathKey);

        if (! $disk->exists($path)) {
            // Files uploaded before a path key became private still sit on the
            // public disk. Serve them here — this endpoint is authorised — so
            // an upgrade does not silently 404 every existing attachment.
            $legacy = Storage::disk('public');

            if (self::isPrivate($pathKey) && $legacy->exists($path)) {
                $disk = $legacy;
            } else {
                abort(404);
            }
        }

        // Derive the root from the disk rather than assuming a path, so this
        // stays correct if the disk is reconfigured.
        $real = realpath($disk->path($path));
        $root = realpath($disk->path(''));

        if (! $real || ! $root || ! str_starts_with($real, $root)) {
            abort(404);
        }

        return response()->file($real, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function removeImage(string $pathKey, ?string $filename): void
    {
        if (! $filename) {
            return;
        }

        $directory = getFilePath($pathKey);

        $this->disk($pathKey)->delete([
            "$directory/$filename",
            "$directory/thumb_$filename",
        ]);
    }
}
