<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Generates the demo imagery for seeded catalogue records.
 *
 * The purchased package ships no product/category photography, so rather than
 * leaving broken image URLs the seeder renders branded placeholders at the
 * exact dimensions the storefront expects.
 */
class PlaceholderImage
{
    private const PALETTE = [
        ['bg' => 'fff3e6', 'fg' => 'ff7a00'],
        ['bg' => 'eef2ff', 'fg' => '4c5bd4'],
        ['bg' => 'ecfdf5', 'fg' => '0f9d76'],
        ['bg' => 'fef2f2', 'fg' => 'e04848'],
        ['bg' => 'f5f3ff', 'fg' => '7c4ddb'],
        ['bg' => 'f0f9ff', 'fg' => '0a84c2'],
    ];

    /** Render a placeholder and return the stored filename. */
    public static function make(string $pathKey, string $label, int $seed = 0, bool $withThumb = false): string
    {
        $size = getFileSize($pathKey) ?: '600x600';
        [$width, $height] = array_map('intval', explode('x', $size));

        $colors = self::PALETTE[abs($seed) % count(self::PALETTE)];
        $filename = Str::uuid()->toString() . '.png';
        $directory = getFilePath($pathKey);

        Storage::disk('public')->makeDirectory($directory);
        Storage::disk('public')->put("$directory/$filename", self::render($width, $height, $colors));

        if ($withThumb && ($thumbSize = getThumbSize($pathKey))) {
            [$tw, $th] = array_map('intval', explode('x', $thumbSize));
            Storage::disk('public')->put("$directory/thumb_$filename", self::render($tw, $th, $colors));
        }

        return $filename;
    }

    private static function render(int $width, int $height, array $colors): string
    {
        $manager = new ImageManager(new Driver());
        $image = $manager->createImage($width, $height)->fill('#' . $colors['bg']);

        $cx = (int) ($width / 2);
        $cy = (int) ($height / 2);
        $r = (int) (min($width, $height) * 0.28);
        $fg = '#' . $colors['fg'];

        // A simple wrench-and-cog motif keeps every tile visually distinct
        // without depending on a bundled font.
        $image->drawCircle(function ($circle) use ($cx, $cy, $r, $fg, $colors) {
            $circle->at($cx, $cy);
            $circle->radius($r);
            $circle->background('#' . $colors['bg']);
            $circle->border($fg, (int) max(2, $r * 0.10));
        });

        $barW = (int) max(4, $r * 0.90);
        $barH = (int) max(3, $r * 0.24);

        $image->drawRectangle(function ($rect) use ($cx, $cy, $barW, $barH, $fg) {
            $rect->at($cx - (int) ($barW / 2), $cy - (int) ($barH / 2));
            $rect->size($barW, $barH);
            $rect->background($fg);
        });

        $image->drawRectangle(function ($rect) use ($cx, $cy, $barW, $barH, $fg) {
            $rect->at($cx - (int) ($barH / 2), $cy - (int) ($barW / 2));
            $rect->size($barH, $barW);
            $rect->background($fg);
        });

        return (string) $image->encodeUsingFileExtension('png');
    }
}
