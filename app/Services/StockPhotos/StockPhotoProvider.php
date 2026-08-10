<?php

namespace App\Services\StockPhotos;

/**
 * A source of catalogue photography for the demo/bootstrap import.
 *
 * Implementations must only return imagery whose licence permits commercial
 * use without a visible credit — the storefront has nowhere to put one, and a
 * shop that silently drops required attribution is not shippable. Wikimedia
 * Commons is deliberately not implemented here for that reason: its automotive
 * material is almost entirely CC BY-SA.
 */
interface StockPhotoProvider
{
    /** Provider name, for logging and the --source option. */
    public function name(): string;

    /** True when the provider has the credentials it needs. */
    public function isConfigured(): bool;

    /**
     * Candidate photos for a search term, best first.
     *
     * More than one is returned so the caller can reject an irrelevant top
     * result and try the next, and so two products sharing a term do not end
     * up with the same picture.
     *
     * @return list<array{url: string, credit: string, alt: string, page: string}>
     *         Empty when nothing matched. `alt` is the provider's own
     *         description — the only way to judge, without opening it, whether
     *         a match is actually relevant.
     */
    public function search(string $term, int $limit = 1): array;
}
