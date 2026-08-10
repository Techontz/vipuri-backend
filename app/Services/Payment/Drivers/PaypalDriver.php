<?php

namespace App\Services\Payment\Drivers;

use App\Models\Deposit;
use App\Services\Payment\PaymentDriver;
use Illuminate\Support\Facades\Http;

/**
 * PayPal REST (Orders v2).
 */
class PaypalDriver implements PaymentDriver
{
    public function requiredCredentials(): array
    {
        return ['PAYPAL_CLIENT_ID', 'PAYPAL_CLIENT_SECRET'];
    }

    public function isConfigured(): bool
    {
        return (bool) (config('services.paypal.client_id') && config('services.paypal.client_secret'));
    }

    private function baseUrl(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function accessToken(): ?string
    {
        $response = Http::asForm()
            ->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.client_secret'))
            ->post($this->baseUrl() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        return $response->successful() ? $response->json('access_token') : null;
    }

    public function initiate(Deposit $deposit): array
    {
        $token = $this->accessToken();

        if (! $token) {
            return ['type' => 'error', 'message' => 'Could not authenticate with PayPal'];
        }

        $response = Http::withToken($token)->post($this->baseUrl() . '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $deposit->trx,
                'custom_id' => $deposit->trx,
                'amount' => [
                    'currency_code' => strtoupper($deposit->method_currency ?: 'USD'),
                    'value' => number_format((float) $deposit->final_amount, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'return_url' => $deposit->success_url . '?trx=' . $deposit->trx,
                'cancel_url' => $deposit->failed_url,
            ],
        ]);

        if ($response->failed()) {
            return ['type' => 'error', 'message' => 'PayPal rejected the payment request'];
        }

        $approve = collect($response->json('links', []))->firstWhere('rel', 'approve')['href'] ?? null;

        return $approve
            ? ['type' => 'redirect', 'url' => $approve]
            : ['type' => 'error', 'message' => 'PayPal did not return an approval link'];
    }

    public function verify(array $payload): array
    {
        $orderId = $payload['resource']['id'] ?? $payload['token'] ?? null;

        if (! $orderId) {
            return ['success' => false, 'message' => 'Malformed PayPal payload'];
        }

        $token = $this->accessToken();

        if (! $token) {
            return ['success' => false, 'message' => 'Could not authenticate with PayPal'];
        }

        $capture = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($this->baseUrl() . "/v2/checkout/orders/{$orderId}/capture");

        $status = $capture->json('status');

        // Read from PayPal's capture response, not from the payload.
        $trx = $capture->json('purchase_units.0.reference_id')
            ?? $capture->json('purchase_units.0.payments.captures.0.custom_id');

        if (! $trx) {
            return ['success' => false, 'message' => 'PayPal order carries no VIPURI reference'];
        }

        $amount = $capture->json('purchase_units.0.payments.captures.0.amount.value');

        return [
            'success' => $status === 'COMPLETED',
            'trx' => $trx,
            // initiate() sends the value in major units, as final_amount is.
            'amount' => $amount !== null ? (float) $amount : null,
            'message' => 'PayPal order ' . ($status ?? 'unknown'),
        ];
    }
}
