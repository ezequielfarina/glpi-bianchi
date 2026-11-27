<?php

declare(strict_types=1);

namespace App\Tools\Net;

/**
 * Validação estrita de alvos dos diagnósticos: IPv4, IPv6 ou nome DNS (RFC 1123, aceita "_" de SRV/DKIM).
 * Rejeita qualquer coisa que pudesse virar opção de linha de comando (ex.: "-oX").
 */
final class Host
{
    public static function isIp(string $v): bool
    {
        return false !== filter_var($v, \FILTER_VALIDATE_IP);
    }

    public static function isName(string $v): bool
    {
        return \strlen($v) <= 253 && 1 === preg_match('/^(?=.*[a-z_])([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?\.)*[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?\.?$/i', $v);
    }

    /** IP ou nome; devolve normalizado ou lança \InvalidArgumentException com mensagem para o usuário. */
    public static function parse(?string $v): string
    {
        $v = trim((string) $v);
        if ('' === $v) {
            throw new \InvalidArgumentException('Informe um IP ou nome.');
        }
        if (self::isIp($v)) {
            return $v;
        }
        if (self::isName($v)) {
            return strtolower(rtrim($v, '.'));
        }

        throw new \InvalidArgumentException('IP ou nome inválido.');
    }

    /** Faixa especial (privada, CGNAT, loopback, link-local...) — não consultável em whois/ASN. */
    public static function specialRange(string $ip): ?string
    {
        if (false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            if (str_starts_with(strtolower($ip), 'fe80:') || '::1' === $ip) {
                return 'IPv6 local';
            }
            if (1 === preg_match('/^f[cd]/i', $ip)) {
                return 'IPv6 ULA (RFC 4193)';
            }

            return null;
        }
        $n = ip2long($ip);
        foreach ([
            ['10.0.0.0', 8, 'Privado (RFC 1918)'], ['172.16.0.0', 12, 'Privado (RFC 1918)'], ['192.168.0.0', 16, 'Privado (RFC 1918)'],
            ['100.64.0.0', 10, 'CGNAT (RFC 6598)'], ['127.0.0.0', 8, 'Loopback'], ['169.254.0.0', 16, 'Link-local (APIPA)'],
            ['0.0.0.0', 8, 'Rede "este host"'], ['224.0.0.0', 4, 'Multicast'], ['240.0.0.0', 4, 'Reservado'],
            ['192.0.2.0', 24, 'Documentação (RFC 5737)'], ['198.51.100.0', 24, 'Documentação (RFC 5737)'], ['203.0.113.0', 24, 'Documentação (RFC 5737)'],
            ['198.18.0.0', 15, 'Benchmark (RFC 2544)'],
        ] as [$net, $len, $label]) {
            $mask = -1 << (32 - $len) & 0xFFFFFFFF;
            if (($n & $mask) === (ip2long($net) & $mask)) {
                return $label;
            }
        }

        return null;
    }
}
