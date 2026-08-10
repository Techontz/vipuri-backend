<?php

namespace App\Services;

use App\Models\CartContext;

/**
 * Identifies the current shopper.
 *
 * Authenticated customers are keyed by `user_id`. Guests send an opaque cart
 * token in the `X-Cart-Token` header (generated once by the storefront and kept
 * in a cookie), which is stored in `carts.session_id`.
 *
 * The request is resolved on every call rather than injected once: Laravel
 * caches a controller instance on its Route, so a constructor-injected Request
 * would still hold the first request that ever hit that route — which under a
 * persistent worker would let one shopper read another's cart.
 */
class CartIdentity
{
    public const HEADER = 'X-Cart-Token';

    public function userId(): ?int
    {
        return auth('user')->id();
    }

    public function sessionId(): ?string
    {
        if ($this->userId()) {
            return null;
        }

        $token = trim((string) request()->header(self::HEADER));

        return $token !== '' ? substr($token, 0, 100) : null;
    }

    /** True when we cannot identify the shopper at all. */
    public function isAnonymousWithoutToken(): bool
    {
        return ! $this->userId() && ! $this->sessionId();
    }

    /** Constrain a query on a table that has user_id + session_id columns. */
    public function scope($query, string $userColumn = 'user_id', string $sessionColumn = 'session_id')
    {
        $userId = $this->userId();
        $sessionId = $this->sessionId();

        return $query->where(function ($q) use ($userId, $sessionId, $userColumn, $sessionColumn) {
            if ($userId) {
                $q->where($userColumn, $userId);
            } else {
                // A null token must never match somebody else's rows.
                $q->where($sessionColumn, $sessionId ?? '__none__');
            }
        });
    }

    /** Owner columns to persist on a new cart/wishlist row. */
    public function ownerAttributes(): array
    {
        return [
            'user_id' => $this->userId(),
            'session_id' => $this->sessionId(),
        ];
    }

    public function context(): CartContext
    {
        $attributes = $this->userId()
            ? ['user_id' => $this->userId()]
            : ['session_id' => $this->sessionId() ?? '__none__'];

        return CartContext::firstOrCreate($attributes);
    }
}
