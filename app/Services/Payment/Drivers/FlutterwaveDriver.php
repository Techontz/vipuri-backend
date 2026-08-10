<?php

namespace App\Services\Payment\Drivers;

use App\Models\Deposit;
use App\Services\Payment\PaymentDriver;
use Illuminate\Support\Facades\Http;

/**
 * Flutterwave hosted checkout.
 *
 * This is the practical route to Tanzanian mobile money (M-Pesa, Tigo Pesa,
 * Airtel Money, Halopesa) as well as cards, all under one integration.
 */
class FlutterwaveDriver implements PaymentDriver
{
    public function requiredCredentials(): array
    {
        return ['FLUTTERWAVE_PUBLIC_KEY', 'FLUTTERWAVE_SECRET_KEY'];
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.flutterwave.secret');
    }

    public function initiate(Deposit $deposit): array
    {
        $order = $deposit->order;
        $customer = $deposit->user;
        $address = $order?->shipping_address;

        $response = Http::withToken(config('services.flutterwave.secret'))
            ->post('https://api.flutterwave.com/v3/payments', [
                'tx_ref' => $deposit->trx,
                'amount' => (float) $deposit->final_amount,
                'currency' => strtoupper($deposit->method_currency ?: 'TZS'),
                'redirect_url' => $deposit->success_url,
                'payment_options' => 'card,mobilemoneytanzania,banktransfer',
                'customer' => [
                    'email' => $customer?->email ?? ($address?->email ?? 'customer@vipuri.co.tz'),
                    'phonenumber' => $customer?->mobile ?? ($address?->mobile ?? ''),
                    'name' => $customer?->fullname ?? trim(($address?->firstname ?? '') . ' ' . ($address?->lastname ?? '')),
                ],
                'customizations' => [
                    'title' => 'VIPURI',
                    'description' => 'Order ' . ($order?->order_number ?? $deposit->trx),
                ],
            ]);

        if ($response->failed() || $response->json('status') !== 'success') {
            return [
                'type' => 'error',
                'message' => $response->json('message') ?? 'Flutterwave rejected the payment request',
            ];
        }

        return ['type' => 'redirect', 'url' => $response->json('data.link')];
    }

    public function verify(array $payload): array
    {
        $transactionId = $payload['data']['id'] ?? $payload['transaction_id'] ?? null;

        if (! $transactionId) {
            return ['success' => false, 'message' => 'Malformed Flutterwave payload'];
        }

        $verify = Http::withToken(config('services.flutterwave.secret'))
            ->get("https://api.flutterwave.com/v3/transactions/{$transactionId}/verify");

        $data = $verify->json('data', []);

        // tx_ref is read from Flutterwave's answer, never from the payload —
        // otherwise one successful transaction could be pointed at any deposit.
        $trx = $data['tx_ref'] ?? null;

        if (! $trx) {
            return ['success' => false, 'message' => 'Flutterwave transaction carries no VIPURI reference'];
        }

        return [
            'success' => ($data['status'] ?? null) === 'successful',
            'trx' => $trx,
            // initiate() sends `amount` in major units, as final_amount is.
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'message' => 'Flutterwave transaction ' . ($data['status'] ?? 'unknown'),
        ];
    }
}
