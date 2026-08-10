<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCardResource;
use App\Models\Product;
use App\Models\Wishlist;
use App\Services\CartIdentity;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function __construct(private readonly CartIdentity $identity) {}

    public function index()
    {
        $rows = $this->identity->scope(Wishlist::query())
            ->with(['product' => fn ($q) => $q->with('media', 'brand', 'variations', 'stockUnit')])
            ->orderByDesc('id')
            ->get()
            ->filter(fn ($row) => $row->product);

        return responseSuccess('wishlists', 'Wishlist fetched', [
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'product' => new ProductCardResource($row->product),
            ])->values(),
            'count' => $rows->count(),
        ]);
    }

    public function add(int $productId)
    {
        if ($this->identity->isAnonymousWithoutToken()) {
            return responseError('missing_cart_token', ['Missing cart token']);
        }

        Product::active()->findOrFail($productId);

        $exists = $this->identity->scope(Wishlist::query())
            ->where('product_id', $productId)
            ->first();

        if ($exists) {
            return responseSuccess('already_in_wishlist', 'Already in your wishlist', [
                'count' => $this->count(),
            ]);
        }

        Wishlist::create($this->identity->ownerAttributes() + ['product_id' => $productId]);

        return responseSuccess('added_to_wishlist', 'Added to your wishlist', [
            'count' => $this->count(),
        ]);
    }

    public function remove(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
        ]);

        $query = $this->identity->scope(Wishlist::query());

        if (! empty($data['id'])) {
            $query->whereKey($data['id']);
        } elseif (! empty($data['product_id'])) {
            $query->where('product_id', $data['product_id']);
        } else {
            return responseError('invalid_request', ['Provide an item id or product id']);
        }

        $query->delete();

        return responseSuccess('removed_from_wishlist', 'Removed from your wishlist', [
            'count' => $this->count(),
        ]);
    }

    public function count(): int
    {
        return $this->identity->scope(Wishlist::query())->count();
    }
}
