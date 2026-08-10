<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Ai\ProductAiService;
use Illuminate\Http\Request;

/**
 * Storefront AI: review summarisation and product Q&A chat.
 */
class AiController extends Controller
{
    public function __construct(private readonly ProductAiService $ai) {}

    public function reviewSummary(Request $request)
    {
        if (! gs('ai_review_summary')) {
            return responseError('feature_disabled', ['AI review summaries are turned off']);
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'prompt' => ['nullable', 'string', 'max:2000'],
        ]);

        $product = Product::select('id', 'name')->findOrFail($data['product_id']);

        $result = $this->ai->summariseReviews($product, $data['prompt'] ?? null);

        if (($result['status'] ?? 'error') !== 'success') {
            return responseError($result['code'] ?? 'ai_failed', [$result['message'] ?? 'Failed to generate summary']);
        }

        return responseSuccess('ai_review_summary', 'Review summary generated', [
            'summary' => $result['content'],
            'total_reviews' => $result['total_reviews'] ?? 0,
            'average_rating' => $result['average_rating'] ?? 0,
        ]);
    }

    public function chat(Request $request)
    {
        if (! gs('ai_product_chat')) {
            return responseError('feature_disabled', ['AI product chat is turned off']);
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $product = Product::findOrFail($data['product_id']);

        $result = $this->ai->chat($product, trim($data['message']));

        if (($result['status'] ?? 'error') !== 'success') {
            return responseError($result['code'] ?? 'ai_failed', [$result['message'] ?? 'Failed to generate a reply']);
        }

        return responseSuccess('ai_chat', 'Reply generated', ['reply' => $result['content']]);
    }
}
