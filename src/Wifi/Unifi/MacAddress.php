<?php

declare(strict_types=1);

namespace App\Wifi\Unifi;

/** MAC em qualquer formato (AA-BB-CC-DD-EE-FF, aabb.ccdd.eeff, AABBCCDDEEFF…) → aa:bb:cc:dd:ee:ff. */
final class MacAddress
{
    public static function normalize(string $input): ?string
    {
        $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $input));
        if (12 !== \strlen($hex) || 1 !== preg_match('/^[0-9a-f]{2}[:\-.\s]?[0-9a-f]{2}([:\-.\s]?[0-9a-f]{2}){4}$|^[0-9a-f]{4}\.[0-9a-f]{4}\.[0-9a-f]{4}$/i', trim($input))) {
            return null;
        }
        if ('000000000000' === $hex || 'ffffffffffff' === $hex) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    /** MAC aleatório/privado (bit "localmente administrado"): iPhone/Android/Windows trocam por rede — avisar o usuário. */
    public static function isRandomized(string $mac): bool
    {
        return (bool) (hexdec(substr($mac, 1, 1)) & 0x2);
    }

    /** bit multicast: não pode ser endereço de um cliente */
    public static function isMulticast(string $mac): bool
    {
        return (bool) (hexdec(substr($mac, 0, 2)) & 0x1);
    }
}
