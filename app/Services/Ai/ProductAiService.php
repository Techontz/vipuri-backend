<?php

namespace App\Services\Ai;

use App\Models\Product;
use App\Models\ProductReview;

/**
 * The three AI features carried over from the source system:
 *   1. admin product-copy generation
 *   2. storefront review summarisation
 *   3. storefront product Q&A chat
 */
class ProductAiService
{
    /** Generate product copy for the admin product form. */
    public function generateProductContent(string $productName, array $hints = []): array
    {
        $context = [];

        if (! empty($hints['brand'])) {
            $context[] = "Brand: {$hints['brand']}";
        }

        if (! empty($hints['category'])) {
            $context[] = "Category: {$hints['category']}";
        }

        foreach (['vehicle_year', 'vehicle_model', 'vehicle_engine', 'vehicle_engine_type'] as $key) {
            if (! empty($hints[$key])) {
                $context[] = keyToTitle($key) . ': ' . $hints[$key];
            }
        }

        $contextText = $context ? implode("\n", $context) . "\n" : '';

        $prompt = "Product name: {$productName}\n{$contextText}\n"
            . "Write marketing copy for this auto part / vehicle accessory sold in Tanzania.\n"
            . "Return STRICT JSON with exactly these keys and no markdown fences:\n"
            . '{"short_description": string (max 220 chars), '
            . '"description": string (HTML with <p> and <ul><li> allowed, 120-220 words), '
            . '"meta_title": string (max 60 chars), '
            . '"meta_description": string (max 155 chars), '
            . '"meta_keywords": string (comma separated, 5-10 terms), '
            . '"specifications": array of {"key": string, "value": string} (4-8 entries)}';

        $result = AiGenerator::generateDefault(
            'You are a senior automotive e-commerce copywriter. Output valid JSON only, never markdown.',
            $prompt,
            ['temperature' => 0.6, 'maxTokens' => 1200],
        );

        if (($result['status'] ?? 'error') !== 'success') {
            return $result;
        }

        $decoded = $this->decodeJson($result['content']);

        if (! $decoded) {
            return ['status' => 'error', 'message' => 'The AI response could not be parsed'];
        }

        return ['status' => 'success', 'content' => $decoded];
    }

    /** Summarise approved reviews for a product. */
    public function summariseReviews(Product $product, ?string $userPrompt = null): array
    {
        $query = ProductReview::approved()->where('product_id', $product->id);
        $total = (clone $query)->count();

        if ($total < 1) {
            return ['status' => 'error', 'code' => 'no_reviews', 'message' => 'No reviews found for this product'];
        }

        $average = round((float) (clone $query)->avg('rating'), 2);
        $reviews = (clone $query)->orderByDesc('id')->take(80)->get(['rating', 'review']);

        $lines = [];
        $charCount = 0;

        foreach ($reviews as $review) {
            $text = trim((string) $review->review);
            $rating = $review->rating ? (int) $review->rating : null;

            if ($text === '' && ! $rating) {
                continue;
            }

            $line = $rating ? "Rating: {$rating}/5" : 'Rating: N/A';

            if ($text !== '') {
                $line .= ". Review: {$text}";
            }

            $line = trim(preg_replace('/\s+/', ' ', $line));
            $length = mb_strlen($line);

            if ($charCount + $length + 2 > 8000 || count($lines) >= 60) {
                break;
            }

            $lines[] = "- {$line}";
            $charCount += $length + 2;
        }

        if (empty($lines)) {
            return ['status' => 'error', 'code' => 'no_review_text', 'message' => 'No review text available for summarisation'];
        }

        $instruction = trim((string) $userPrompt) ?: 'Summarise whether this product is good or bad and if it is worth buying.';

        $prompt = "Product: {$product->name}\n"
            . "Average rating: {$average} out of 5 based on {$total} reviews.\n"
            . "The following are recent customer reviews:\n"
            . implode("\n", $lines) . "\n\n"
            . "Instruction: {$instruction}\n\n"
            . 'Return a concise summary with: Overall sentiment, Key pros, Key cons, and a final verdict (Buy/Maybe/Avoid).';

        $result = AiGenerator::generateDefault(
            'You are a product review analyst. Use only the provided reviews. Output concise plain text only.',
            $prompt,
            ['temperature' => 0.4, 'maxTokens' => 512],
        );

        if (($result['status'] ?? 'error') === 'success') {
            $result['total_reviews'] = $total;
            $result['average_rating'] = $average;
        }

        return $result;
    }

    /** Answer a shopper's question about a specific product. */
    public function chat(Product $product, string $message): array
    {
        $product->loadMissing([
            'variations',
            'groupedProducts.groupedProduct',
            'categories',
            'brand',
        ]);

        $lines = [
            "Product name: {$product->name}",
            "Product type: {$product->product_type}",
            'Currency: ' . currencyText(),
            'Current price: ' . showAmount($product->display_price),
        ];

        if ($product->brand) {
            $lines[] = "Brand: {$product->brand->name}";
        }

        if ($product->categories->isNotEmpty()) {
            $lines[] = 'Categories: ' . $product->categories->pluck('name')->implode(', ');
        }

        foreach (['vehicle_year' => 'Vehicle year', 'vehicle_model' => 'Vehicle model', 'vehicle_engine' => 'Vehicle engine', 'vehicle_engine_type' => 'Engine type'] as $column => $label) {
            if ($product->{$column}) {
                $lines[] = "{$label}: {$product->{$column}}";
            }
        }

        if ($short = $this->cleanText($product->short_description ?? '', 400)) {
            $lines[] = "Short description: {$short}";
        }

        if ($description = $this->cleanText($product->description ?? '', 1200)) {
            $lines[] = "Description: {$description}";
        }

        $specs = [];
        $keys = $product->specifications?->key ?? [];
        $values = $product->specifications?->value ?? [];

        foreach ((array) $keys as $index => $key) {
            $value = $values[$index] ?? null;

            if ($key && $value) {
                $specs[] = trim($key) . ': ' . trim($value);
            }
        }

        if ($specs) {
            $lines[] = 'Specifications: ' . implode('; ', array_slice($specs, 0, 20));
        }

        if ($product->variations->isNotEmpty()) {
            $variationLines = $product->variations->take(25)->map(
                fn ($v) => ($v->name ?: 'Variation') . ' | price ' . showAmount($v->price) . ($v->sku ? ", sku {$v->sku}" : '')
            )->implode(' || ');

            $lines[] = "Variations: {$variationLines}";
        }

        if ($product->groupedProducts->isNotEmpty()) {
            $groupLines = $product->groupedProducts->take(20)
                ->filter(fn ($g) => $g->groupedProduct)
                ->map(fn ($g) => $g->groupedProduct->name . ' | price ' . showAmount($g->groupedProduct->price))
                ->implode(' || ');

            if ($groupLines) {
                $lines[] = "Grouped items: {$groupLines}";
            }
        }

        $reviewQuery = ProductReview::approved()->where('product_id', $product->id);
        $totalReviews = (clone $reviewQuery)->count();

        if ($totalReviews > 0) {
            $average = round((float) (clone $reviewQuery)->avg('rating'), 2);
            $lines[] = "Reviews: {$totalReviews} reviews, average rating {$average}/5.";
        }

        $stock = $product->trackInventory()
            ? ($product->stock_quantity > 0 ? "In stock ({$product->stock_quantity})" : 'Out of stock')
            : 'In stock';
        $lines[] = "Availability: {$stock}";

        $prompt = "Product context:\n" . implode("\n", $lines) . "\n\nCustomer question: {$message}";

        return AiGenerator::generateDefault(
            'You are a helpful VIPURI auto-parts sales assistant. Answer using only the supplied product context. '
                . 'If the context does not contain the answer, say so and suggest contacting VIPURI support. '
                . 'Prices are in Tanzanian Shillings (TZS). Keep answers under 150 words.',
            $prompt,
            ['temperature' => 0.3, 'maxTokens' => 400],
        );
    }

    private function cleanText(string $text, int $limit = 500): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($text)));

        return mb_strlen($plain) <= $limit ? $plain : mb_substr($plain, 0, $limit) . '...';
    }

    /** Tolerant JSON decode — models sometimes wrap output in fences. */
    private function decodeJson(string $content): ?array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?|```$/m', '', $content);
        $decoded = json_decode(trim($content), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
