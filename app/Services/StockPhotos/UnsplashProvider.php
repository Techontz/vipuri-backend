<?php

namespace App\Services\StockPhotos;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Unsplash.
 *
 * The Unsplash licence also permits commercial use without attribution. Their
 * API guidelines ask that applications credit photographers where practical —
 * the credit travels into the import log for exactly that reason.
 */
class UnsplashProvider implements StockPhotoProvider
{
    public function name(): string
    {
        return 'unsplash';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.unsplash.key'));
    }

    public function search(string $term, int $limit = 1): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Client-ID ' . config('services.unsplash.key'),
            'Accept-Version' => 'v1',
        ])
            ->timeout(20)
            ->retry(2, 400, throw: false)
            ->get('https://api.unsplash.com/search/photos', [
                'query' => $term,
                'per_page' => max(1, min($limit, 20)),
                'orientation' => 'squarish',
                'content_filter' => 'high',
            ]);

        if (! $response->successful()) {
            Log::warning('Unsplash search failed', ['term' => $term, 'status' => $response->status()]);

            return [];
        }

        $candidates = [];

        foreach ((array) $response->json('results', []) as $photo) {
            $url = $photo['urls']['regular'] ?? $photo['urls']['full'] ?? null;

            if (! $url) {
                continue;
            }

            $candidates[] = [
                'url' => $url,
                'credit' => 'Unsplash / ' . ($photo['user']['name'] ?? 'unknown'),
                'alt' => trim((string) ($photo['alt_description'] ?? '')) ?: '(no description)',
                'page' => (string) ($photo['links']['html'] ?? ''),
            ];
        }

        return $candidates;
    }
}
