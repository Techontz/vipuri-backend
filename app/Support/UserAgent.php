<?php

namespace App\Support;

/**
 * Browser and platform names for the login history screens.
 *
 * Deliberately coarse: this exists so an administrator can recognise a login
 * they don't remember making, not to fingerprint anyone.
 */
class UserAgent
{
    public static function browser(?string $agent): string
    {
        $agent = (string) $agent;

        return match (true) {
            str_contains($agent, 'Edg') => 'Edge',
            str_contains($agent, 'OPR') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            str_contains($agent, 'Firefox') => 'Firefox',
            default => 'Unknown',
        };
    }

    public static function os(?string $agent): string
    {
        $agent = (string) $agent;

        return match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown',
        };
    }
}
