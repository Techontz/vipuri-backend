<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Upload, resize and delete media.
 *
 * Most paths live on the `public` disk and are addressed through /media. Path
 * keys flagged `private` in config/vipuri.php live on the `local` disk instead,
 * which has no URL at all — the only way to read one is {@see download()},
 * behind whatever ownership check the caller applies.
 *
 * Every image the shop stores comes through here — products, categories,
 * brands, offers, campaigns, branches, profiles — so this is where the
 * conversion to WebP belongs. Uploading a JPEG stores a `.webp`, and the
 * filename written to the database is the one that was actually written to
 * disk. There is one pipeline; no caller has to know about any of it.
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

    /**
     * Types converted to WebP.
     *
     * GIF is deliberately absent. GD decodes only the first frame, so
     * converting an animated GIF would quietly flatten it into a still — a
     * worse result than leaving it alone. It is stored as it arrived.
     *
     * HEIC/HEIF are not in {@see ALLOWED_IMAGE_MIMES} at all: GD cannot decode
     * them and Imagick is not installed. In practice this does not bite, since
     * both iOS and Android photo pickers hand an app JPEG.
     */
    private const CONVERT_TO_WEBP = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Quality, chosen for product photography rather than for the smallest
     * possible file. At 82 a typical part photo is a fraction of the JPEG with
     * no visible loss on a phone screen; below about 75, flat painted panels
     * and chrome start to band.
     */
    private const WEBP_QUALITY = 82;

    private const WEBP_THUMB_QUALITY = 76;

    /** Nothing needs to be stored larger than this when no size is configured. */
    private const MAX_UNSIZED_EDGE = 2400;

    /**
     * Ceilings applied before the file is decoded.
     *
     * A few hundred kilobytes of highly compressed PNG can expand to gigabytes
     * in memory, so the pixel count is checked from the header first — after
     * that the decode is bounded.
     */
    private const MAX_BYTES = 12 * 1024 * 1024;

    private const MAX_PIXELS = 40_000_000;

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
        // Sniffed, never taken from the filename: `payload.php` carrying a
        // valid PNG body is a PNG, and is stored as one.
        $mime = (string) $file->getMimeType();
        $sourceExtension = self::ALLOWED_IMAGE_MIMES[$mime] ?? null;

        if (! $sourceExtension) {
            throw new RuntimeException('Only JPG, PNG, GIF or WEBP images are allowed');
        }

        $this->guardUpload($file);

        $extension = $this->storedExtension($pathKey, $mime, $sourceExtension);
        $directory = getFilePath($pathKey);
        $size = getFileSize($pathKey);
        $filename = Str::uuid()->toString() . '.' . $extension;

        Storage::disk('public')->makeDirectory($directory);

        $manager = new ImageManager(new Driver());

        // A phone camera writes the sensor's own orientation and an EXIF tag
        // saying how to turn it. Applying that now means the stored pixels are
        // the right way up for every client — and the tag is dropped on encode,
        // so nothing can rotate it a second time.
        $image = $manager->decodePath($file->getRealPath())->orient();

        if ($size) {
            [$width, $height] = array_map('intval', explode('x', $size));
            $image->cover($width, $height);
        } else {
            // No configured size: keep the proportions, but do not store a
            // 6000px original for something displayed at a few hundred.
            $image->scaleDown(self::MAX_UNSIZED_EDGE, self::MAX_UNSIZED_EDGE);
        }

        Storage::disk('public')->put(
            "$directory/$filename",
            $this->encode($image, $extension, self::WEBP_QUALITY),
        );

        if ($withThumb && ($thumbSize = getThumbSize($pathKey))) {
            [$tw, $th] = array_map('intval', explode('x', $thumbSize));
            $thumb = $manager->decodePath($file->getRealPath())->orient()->cover($tw, $th);

            Storage::disk('public')->put(
                "$directory/thumb_$filename",
                $this->encode($thumb, $extension, self::WEBP_THUMB_QUALITY),
            );
        }

        if ($oldFilename) {
            $this->removeImage($pathKey, $oldFilename);
        }

        return $filename;
    }

    /**
     * The extension the file will actually be stored under.
     *
     * A path key may opt out with `preserve_format`. `logoIcon` does: it holds
     * the favicon, and browser support for a WebP favicon is patchy enough
     * that shrinking one is not worth a missing tab icon.
     */
    private function storedExtension(string $pathKey, string $mime, string $sourceExtension): string
    {
        if (config("vipuri.file_path.$pathKey.preserve_format", false)) {
            return $sourceExtension;
        }

        return in_array($mime, self::CONVERT_TO_WEBP, true) ? 'webp' : $sourceExtension;
    }

    private function encode(ImageInterface $image, string $extension, int $quality): string
    {
        return (string) ($extension === 'webp'
            ? $image->encode(new WebpEncoder(quality: $quality))
            : $image->encodeUsingFileExtension($extension, quality: $quality));
    }

    /**
     * Reject anything unreasonable before it is decoded.
     *
     * Controllers already validate `image` and a max size, but this is the one
     * place every upload passes through, so the ceiling belongs here too —
     * a caller that forgets a rule still cannot hand the decoder a bomb.
     */
    private function guardUpload(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('That image is too large. The limit is 12MB.');
        }

        $dimensions = @getimagesize($file->getRealPath());

        if ($dimensions === false) {
            throw new RuntimeException('That file could not be read as an image');
        }

        if (($dimensions[0] * $dimensions[1]) > self::MAX_PIXELS) {
            throw new RuntimeException('That image has too many pixels to process');
        }
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
