<?php

namespace App\Services\Payment\Drivers;

use App\Models\Deposit;
use App\Services\Payment\PaymentDriver;
use Illuminate\Support\Facades\Http;

/**
 * Paystack hosted checkout.
 */
class PaystackDriver implements PaymentDriver
{
    public function requiredCredentials(): array
    {
        return ['PAYSTACK_PUBLIC_KEY', 'PAYSTACK_SECRET_KEY'];
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.paystack.secret');
    }

    public function initiate(Deposit $deposit): array
    {
        $response = Http::withToken(config('services.paystack.secret'))
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $deposit->user?->email ?? 'customer@vipuri.co.tz',
                // Paystack works in the smallest unit (kobo/cents).
                'amount' => (int) round($deposit->final_amount * 100),
                'currency' => strtoupper($deposit->method_currency ?: 'NGN'),
                'reference' => $deposit->trx,
                'callback_url' => $deposit->success_url,
            ]);

        if ($response->failed() || ! $response->json('status')) {
            return [
                'type' => 'error',
                'message' => $response->json('message') ?? 'Paystack rejected the payment request',
            ];
        }

        return ['type' => 'redirect', 'url' => $response->json('data.authorization_url')];
    }

    public function verify(array $payload): array
    {
        $reference = $payload['data']['reference'] ?? $payload['reference'] ?? null;

        if (! $reference) {
            return ['success' => false, 'message' => 'Malformed Paystack payload'];
        }

        $verify = Http::withToken(config('services.paystack.secret'))
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        return [
            'success' => $verify->json('data.status') === 'success',
            // Paystack echoes the reference we looked up; prefer its copy.
            'trx' => $verify->json('data.reference') ?? $reference,
            // initiate() sends the smallest unit, so divide back out.
            'amount' => $verify->json('data.amount') !== null
                ? ((float) $verify->json('data.amount')) / 100
                : null,
            'message' => 'Paystack transaction ' . ($verify->json('data.status') ?? 'unknown'),
        ];
    }
}
