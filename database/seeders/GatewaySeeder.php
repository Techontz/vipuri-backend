<?php

namespace Database\Seeders;

use App\Constants\Status;
use App\Models\Gateway;
use App\Models\GatewayCurrency;
use Illuminate\Database\Seeder;

/**
 * Payment gateway catalogue.
 *
 * Automatic gateways (code < 1000) are backed by a driver in
 * config/vipuri.php and stay disabled until their credentials exist — the
 * admin panel refuses to enable an unconfigured one so a customer can never be
 * offered a method that cannot complete.
 *
 * Manual gateways (code >= 1000) are confirmed by an administrator and are the
 * practical default for Tanzanian mobile money and bank deposits.
 */
class GatewaySeeder extends Seeder
{
    public function run(): void
    {
        $automatic = [
            [
                'code' => 501,
                'name' => 'Stripe',
                'alias' => 'Stripe',
                'description' => 'Card payments processed by Stripe Checkout.',
                'currencies' => [['name' => 'Stripe (TZS)', 'currency' => 'TZS', 'symbol' => 'TSh', 'rate' => 1]],
            ],
            [
                'code' => 502,
                'name' => 'PayPal',
                'alias' => 'Paypal',
                'description' => 'International payments via PayPal.',
                'currencies' => [['name' => 'PayPal (USD)', 'currency' => 'USD', 'symbol' => '$', 'rate' => 0.00037]],
            ],
            [
                'code' => 503,
                'name' => 'Flutterwave',
                'alias' => 'Flutterwave',
                'description' => 'Cards plus Tanzanian mobile money — M-Pesa, Tigo Pesa, Airtel Money and Halopesa.',
                'currencies' => [['name' => 'Flutterwave (TZS)', 'currency' => 'TZS', 'symbol' => 'TSh', 'rate' => 1]],
            ],
            [
                'code' => 504,
                'name' => 'Paystack',
                'alias' => 'Paystack',
                'description' => 'Card and bank payments via Paystack.',
                'currencies' => [['name' => 'Paystack (KES)', 'currency' => 'KES', 'symbol' => 'KSh', 'rate' => 0.048]],
            ],
        ];

        foreach ($automatic as $data) {
            $gateway = Gateway::updateOrCreate(
                ['alias' => $data['alias']],
                [
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'driver' => $data['alias'],
                    'description' => $data['description'],
                    'status' => Status::DISABLE,
                    'gateway_parameters' => [],
                ],
            );

            foreach ($data['currencies'] as $currency) {
                GatewayCurrency::updateOrCreate(
                    ['method_code' => $gateway->code, 'currency' => $currency['currency']],
                    [
                        'name' => $currency['name'],
                        'symbol' => $currency['symbol'],
                        'gateway_alias' => $gateway->alias,
                        'rate' => $currency['rate'],
                        'min_amount' => 1000,
                        'max_amount' => 100000000,
                        'percent_charge' => 0,
                        'fixed_charge' => 0,
                    ],
                );
            }
        }

        $manual = [
            [
                'code' => 1001,
                'name' => 'M-Pesa (Vodacom)',
                'alias' => 'mpesa-manual',
                'description' => "Send payment to VIPURI Lipa Namba <strong>555 111</strong> using Vodacom M-Pesa, then enter the confirmation code below.\n\n"
                    . '1. Dial *150*00#  2. Choose "Lipa kwa M-Pesa"  3. Choose "Lipa Namba"  4. Enter 555111  5. Enter the amount  6. Confirm.',
                'parameters' => [
                    'transaction_code' => ['title' => 'M-Pesa confirmation code', 'type' => 'text', 'validation' => 'required'],
                    'paying_number' => ['title' => 'Number you paid from', 'type' => 'text', 'validation' => 'required'],
                ],
            ],
            [
                'code' => 1002,
                'name' => 'Tigo Pesa',
                'alias' => 'tigopesa-manual',
                'description' => 'Send payment to VIPURI Lipa Namba <strong>555 222</strong> using Tigo Pesa, then enter the confirmation code below.',
                'parameters' => [
                    'transaction_code' => ['title' => 'Tigo Pesa confirmation code', 'type' => 'text', 'validation' => 'required'],
                    'paying_number' => ['title' => 'Number you paid from', 'type' => 'text', 'validation' => 'required'],
                ],
            ],
            [
                'code' => 1003,
                'name' => 'Airtel Money',
                'alias' => 'airtelmoney-manual',
                'description' => 'Send payment to VIPURI Lipa Namba <strong>555 333</strong> using Airtel Money, then enter the confirmation code below.',
                'parameters' => [
                    'transaction_code' => ['title' => 'Airtel Money confirmation code', 'type' => 'text', 'validation' => 'required'],
                    'paying_number' => ['title' => 'Number you paid from', 'type' => 'text', 'validation' => 'required'],
                ],
            ],
            [
                'code' => 1004,
                'name' => 'Bank Transfer',
                'alias' => 'bank-transfer',
                'description' => "Transfer to:\nBank: CRDB Bank PLC\nAccount name: VIPURI Auto Parts Limited\nAccount number: 0150 1234 5678\nSwift: CORUTZTZ\n\nUpload or type your transfer reference below.",
                'parameters' => [
                    'reference' => ['title' => 'Bank transfer reference', 'type' => 'text', 'validation' => 'required'],
                    'bank_name' => ['title' => 'Bank you paid from', 'type' => 'text', 'validation' => 'required'],
                    'slip' => ['title' => 'Deposit slip', 'type' => 'file', 'validation' => 'nullable'],
                ],
            ],
        ];

        foreach ($manual as $data) {
            $gateway = Gateway::updateOrCreate(
                ['alias' => $data['alias']],
                [
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'driver' => null,
                    'description' => $data['description'],
                    'status' => Status::ENABLE,
                    'gateway_parameters' => $data['parameters'],
                ],
            );

            GatewayCurrency::updateOrCreate(
                ['method_code' => $gateway->code, 'currency' => 'TZS'],
                [
                    'name' => $gateway->name,
                    'symbol' => 'TSh',
                    'gateway_alias' => $gateway->alias,
                    'rate' => 1,
                    'min_amount' => 1000,
                    'max_amount' => 50000000,
                    'percent_charge' => 0,
                    'fixed_charge' => 0,
                ],
            );
        }
    }
}
