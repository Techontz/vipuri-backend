<?php

namespace App\Support;

/**
 * Tanzanian mobile-money numbers.
 *
 * Customers pay by typing their phone number; the network is worked out from
 * the number's prefix, so they never have to pick M-Pesa, Tigo Pesa or Airtel
 * Money themselves.
 */
class MobileMoney
{
    /**
     * Mobile prefixes (the two digits after 255) by network. `gateway_code`
     * is the `method_code` of the matching payment gateway, where one exists.
     */
    private const NETWORKS = [
        'vodacom' => ['name' => 'M-Pesa (Vodacom)', 'gateway_code' => 1001, 'prefixes' => ['74', '75', '76']],
        'tigo' => ['name' => 'Mixx by Yas (Tigo Pesa)', 'gateway_code' => 1002, 'prefixes' => ['65', '67', '71', '77']],
        'airtel' => ['name' => 'Airtel Money', 'gateway_code' => 1003, 'prefixes' => ['68', '69', '78']],
        'halotel' => ['name' => 'HaloPesa (Halotel)', 'gateway_code' => null, 'prefixes' => ['61', '62']],
        'ttcl' => ['name' => 'T-Pesa (TTCL)', 'gateway_code' => null, 'prefixes' => ['73']],
    ];

    /**
     * Normalise any common way of writing a Tanzanian mobile number
     * (0754…, 754…, +255 754…, 255-754-…) to the 12-digit `2557XXXXXXXX`
     * form providers expect. Returns null when it is not a mobile number.
     */
    public static function normalise(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);

        if (str_starts_with($digits, '255')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[67]\d{8}$/', $digits) ? '255' . $digits : null;
    }

    /**
     * The network a normalised number belongs to.
     *
     * @return array{key: string, name: string, gateway_code: int|null}|null
     */
    public static function network(string $msisdn): ?array
    {
        $prefix = substr($msisdn, 3, 2);

        foreach (self::NETWORKS as $key => $network) {
            if (in_array($prefix, $network['prefixes'], true)) {
                return ['key' => $key, 'name' => $network['name'], 'gateway_code' => $network['gateway_code']];
            }
        }

        return null;
    }

    /** `255754111001` → `+255 754 111 001`, for receipts and the admin. */
    public static function display(string $msisdn): string
    {
        return sprintf('+255 %s %s %s', substr($msisdn, 3, 3), substr($msisdn, 6, 3), substr($msisdn, 9, 3));
    }
}
