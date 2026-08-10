<?php

namespace App\Services\Payment;

use App\Models\Deposit;

/**
 * Contract every automated gateway driver implements.
 */
interface PaymentDriver
{
    /**
     * Begin a payment. Returns what the storefront should do next.
     *
     * @return array{type: 'redirect'|'form'|'error', url?: string, fields?: array, message?: string}
     */
    public function initiate(Deposit $deposit): array;

    /**
     * Verify a callback/webhook payload.
     *
     * The payload only ever identifies *which* transaction to look up. Both
     * `trx` and `amount` in the result must come from what the provider
     * reported back, never from the payload — otherwise anyone holding a
     * genuinely successful transaction of their own could point a callback at
     * somebody else's deposit and settle it.
     *
     * `amount` is expressed in the same unit as `Deposit::$final_amount`.
     *
     * @return array{success: bool, trx?: string, amount?: float, message?: string}
     */
    public function verify(array $payload): array;

    /** Credentials this driver needs, for the admin gateway form. */
    public function requiredCredentials(): array;

    /** True when every required credential is present. */
    public function isConfigured(): bool;
}
