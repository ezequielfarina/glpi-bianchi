<?php

declare(strict_types=1);

namespace App\Organization\Service;

/**
 * Normalização de redes CIDR (IPv4/IPv6) para o cadastro de filiais.
 *
 * Aceita "192.168.10.0/24", "192.168.10.77/24" (zera os bits de host → .0/24)
 * e IP sem prefixo (vira /32 ou /128). Retorna null se inválido.
 */
final class Cidr
{
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        [$ip, $prefix] = str_contains($value, '/') ? explode('/', $value, 2) : [$value, null];
        $bin = @inet_pton(trim($ip));
        if (false === $bin) {
            return null;
        }
        $bits = \strlen($bin) * 8;
        if (null === $prefix) {
            $len = $bits;
        } elseif (ctype_digit(trim($prefix)) && (int) $prefix <= $bits) {
            $len = (int) $prefix;
        } else {
            return null;
        }

        // aplica a máscara byte a byte
        $masked = '';
        for ($i = 0, $n = \strlen($bin); $i < $n; ++$i) {
            $keep = max(0, min(8, $len - $i * 8));
            $mask = 0 === $keep ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $masked .= \chr(\ord($bin[$i]) & $mask);
        }

        return inet_ntop($masked).'/'.$len;
    }
}
