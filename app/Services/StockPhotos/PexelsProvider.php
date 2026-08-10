<?php

namespace App\Services\StockPhotos;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pexels.
 *
 * The Pexels licence allows commercial use and modification with no
 * attribution required, which is why the storefront needs no credit line.
 * The photographer is still recorded in the returned credit so the import log
 * says where each picture came from.
 */
class PexelsProvider implements StockPhotoProvider
{
    public function name(): string
    {
        return 'pexels';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.pexels.key'));
    }

    public function search(string $term, int $limit = 1): array
    {
        $response = Http::withHeaders(['Authorization' => (string) config('services.pexels.key')])
            ->timeout(20)
            ->retry(2, 400, throw: false)
            ->get('https://api.pexels.com/v1/search', [
                'query' => $term,
                'per_page' => max(1, min($limit, 20)),
                'orientation' => 'square',
            ]);

        if (! $response->successful()) {
            Log::warning('Pexels search failed', ['term' => $term, 'status' => $response->status()]);

            return [];
        }

        $candidates = [];

        foreach ((array) $response->json('photos', []) as $photo) {
            // `large` is ~1200px on its longest edge — plenty for a 600px
            // product tile, and a fraction of the original's weight.
            $url = $photo['src']['large'] ?? $photo['src']['original'] ?? null;

            if (! $url) {
                continue;
            }

            $candidates[] = [
                'url' => $url,
                'credit' => 'Pexels / ' . ($photo['photographer'] ?? 'unknown'),
                'alt' => trim((string) ($photo['alt'] ?? '')) ?: '(no description)',
                'page' => (string) ($photo['url'] ?? ''),
            ];
        }

        return $candidates;
    }
}
