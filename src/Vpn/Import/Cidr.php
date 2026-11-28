<?php

declare(strict_types=1);

namespace App\Vpn\Import;

/** "192.168.10.0 255.255.255.0" / "192.168.10.0/24" / "10.0.0.1" / "2001:db8::/32" → CIDR. */
final class Cidr
{
    public static function from(string $v): string
    {
        $v = trim($v);
        if (str_contains($v, '/')) {
            return strtolower($v);
        }
        $parts = preg_split('/\s+/', $v) ?: [];
        $ip = $parts[0] ?? '';
        if (str_contains($ip, ':')) {
            return strtolower($ip).'/128';
        }
        $mask = $parts[1] ?? '255.255.255.255';
        $long = ip2long($mask);
        $bits = false === $long ? 32 : substr_count(decbin($long & 0xFFFFFFFF), '1');

        return $ip.'/'.$bits;
    }
}
