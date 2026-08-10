<?php

namespace App\Services\Payment\Drivers;

use App\Models\Deposit;
use App\Services\Payment\PaymentDriver;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout. Creates a hosted session and redirects the shopper.
 */
class StripeDriver implements PaymentDriver
{
    public function requiredCredentials(): array
    {
        return ['STRIPE_SECRET_KEY', 'STRIPE_PUBLISHABLE_KEY'];
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.stripe.secret');
    }

    public function initiate(Deposit $deposit): array
    {
        $response = Http::withToken(config('services.stripe.secret'))
            ->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $deposit->success_url . '?trx=' . $deposit->trx,
                'cancel_url' => $deposit->failed_url,
                'client_reference_id' => $deposit->trx,
                'metadata[trx]' => $deposit->trx,
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]' => strtolower($deposit->method_currency ?: 'tzs'),
                // Stripe expects the smallest currency unit; TZS has no decimals.
                'line_items[0][price_data][unit_amount]' => (int) round($deposit->final_amount),
                'line_items[0][price_data][product_data][name]' => 'VIPURI order ' . ($deposit->order?->order_number ?? $deposit->trx),
            ]);

        if ($response->failed()) {
            return [
                'type' => 'error',
                'message' => $response->json('error.message') ?? 'Stripe rejected the payment request',
            ];
        }

        return ['type' => 'redirect', 'url' => $response->json('url')];
    }

    public function verify(array $payload): array
    {
        $sessionId = $payload['data']['object']['id'] ?? null;

        if (! $sessionId) {
            return ['success' => false, 'message' => 'Malformed Stripe payload'];
        }

        // Re-read the session from Stripe rather than trusting the webhook body.
        $session = Http::withToken(config('services.stripe.secret'))
            ->get("https://api.stripe.com/v1/checkout/sessions/{$sessionId}");

        if ($session->failed()) {
            return ['success' => false, 'message' => 'Could not verify the Stripe session'];
        }

        // Both taken from Stripe's answer, not from the payload: the payload
        // chooses which session to read and nothing more.
        $trx = $session->json('metadata.trx') ?? $session->json('client_reference_id');

        if (! $trx) {
            return ['success' => false, 'message' => 'Stripe session carries no VIPURI reference'];
        }

        return [
            'success' => $session->json('payment_status') === 'paid',
            'trx' => $trx,
            // initiate() sends unit_amount in the same unit as final_amount.
            'amount' => (float) $session->json('amount_total'),
            'message' => 'Stripe payment ' . $session->json('payment_status'),
        ];
    }
}
