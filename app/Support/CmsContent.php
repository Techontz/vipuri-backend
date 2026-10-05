<?php

namespace App\Support;

use App\Models\Frontend;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads CMS sections and hands the frontend ready-to-render values.
 *
 * The source system stored bare filenames and let Blade resolve them with
 * `frontendImage($section, $file)`. Since the storefront is now a separate
 * application, the API resolves those filenames to absolute URLs here so the
 * client never has to know the storage layout.
 */
class CmsContent
{
    /** Keys inside a section payload that hold an uploaded filename. */
    private const IMAGE_KEYS = ['image', 'banner', 'thumb', 'logo', 'favicon', 'logo_dark'];

    /** Fetch one `<key>.content` block with image fields resolved. */
    public static function content(string $key): ?array
    {
        $row = Frontend::where('data_keys', "$key.content")->first();

        return $row ? self::resolve((array) $row->data_values, $key) : null;
    }

    /**
     * Fetch a singleton `<key>.data` row — the CMS shape used for site-wide
     * settings blocks such as the cookie notice, maintenance page and logos.
     */
    public static function data(string $key): ?array
    {
        $row = Frontend::where('data_keys', "$key.data")->first();

        return $row ? self::resolve((array) $row->data_values, $key) : null;
    }

    /** Fetch every `<key>.element` row with image fields resolved. */
    public static function elements(string $key): Collection
    {
        return Frontend::where('data_keys', "$key.element")
            ->orderBy('id')
            ->get()
            ->map(fn (Frontend $row) => self::resolve((array) $row->data_values, $key) + [
                'id' => $row->id,
                'slug' => $row->slug,
                'created_at' => $row->created_at?->toIso8601String(),
            ]);
    }

    /**
     * Fetch the `.content` block and `.element` list for many sections at once.
     *
     * The home page renders seventeen sections; asking for them one at a time
     * costs thirty-four queries on the busiest endpoint in the system. The
     * result is identical to calling {@see content()} and {@see elements()} per
     * key — keys with neither a block nor any elements are simply absent.
     *
     * @param  array<string>  $keys
     * @return array<string, mixed>  Keyed `<key>.content` / `<key>.element`.
     */
    public static function sections(array $keys): array
    {
        $wanted = [];

        foreach ($keys as $key) {
            $wanted[] = "$key.content";
            $wanted[] = "$key.element";
        }

        $rows = Frontend::whereIn('data_keys', $wanted)->orderBy('id')->get();

        $sections = [];

        foreach ($keys as $key) {
            $content = $rows->firstWhere('data_keys', "$key.content");

            if ($content) {
                $sections["$key.content"] = self::resolve((array) $content->data_values, $key);
            }

            $elements = $rows->where('data_keys', "$key.element")
                ->map(fn (Frontend $row) => self::resolve((array) $row->data_values, $key) + [
                    'id' => $row->id,
                    'slug' => $row->slug,
                    'created_at' => $row->created_at?->toIso8601String(),
                ]);

            if ($elements->isNotEmpty()) {
                $sections["$key.element"] = $elements->values();
            }
        }

        return $sections;
    }

    /** Fetch a single element by slug. */
    public static function element(string $key, string $slug): ?array
    {
        $row = Frontend::where('data_keys', "$key.element")->where('slug', $slug)->first();

        if (! $row) {
            return null;
        }

        return self::resolve((array) $row->data_values, $key) + [
            'id' => $row->id,
            'slug' => $row->slug,
            'seo' => $row->seo_content,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * Rewrite image filenames to absolute URLs.
     *
     * `$section` is the directory the theme stored the upload under, e.g.
     * `assets/images/frontend/banner/<file>`.
     */
    public static function resolve(array $values, string $section): array
    {
        foreach ($values as $key => $value) {
            if (! is_string($value) || $value === '' || ! self::looksLikeImageKey($key)) {
                continue;
            }

            if (Str::startsWith($value, ['http://', 'https://', '/'])) {
                continue;
            }

            $values[$key] = self::url($section, $value);
        }

        return $values;
    }

    public static function url(string $section, ?string $filename): ?string
    {
        if (! $filename) {
            return null;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        // Most CMS images live under the section's folder inside the frontend
        // directory. A few sections — maintenance is the one in the source
        // system — have a directory of their own in the file-path map.
        // Uploads through the admin API are stored flat in the frontend
        // directory, so that is checked as well.
        $candidates = ["assets/images/frontend/$section/$filename", "assets/images/frontend/$filename"];

        if ($configured = config("vipuri.file_path.$section.path")) {
            $candidates[] = "$configured/$filename";
        }

        foreach ($candidates as $path) {
            if ($disk->exists($path)) {
                return $disk->url($path);
            }
        }

        return null;
    }

    private static function looksLikeImageKey(string $key): bool
    {
        return in_array($key, self::IMAGE_KEYS, true) || Str::endsWith($key, '_image');
    }
}
