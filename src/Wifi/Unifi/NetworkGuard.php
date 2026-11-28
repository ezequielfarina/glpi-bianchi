<?php

declare(strict_types=1);

namespace App\Wifi\Unifi;

/**
 * O controller UniFi fica na rede interna: bloqueia apontar a integração para loopback, link-local/metadata
 * (169.254.0.0/16), endereços reservados ou internet — senão "endereço do controller" e "Ler certificado"
 * virariam SSRF/varredura de portas a partir do CT. Checado ao salvar e a cada conexão (DNS rebinding).
 * Nome que não resolve passa: a conexão falha sozinha, com a mensagem de DNS.
 */
final class NetworkGuard
{
    public static function assertInternal(string $url): void
    {
        $host = trim((string) parse_url($url, \PHP_URL_HOST), '[]');
        if ('' === $host) {
            throw new UnifiException('Endereço do controller inválido.');
        }
        $ips = false !== filter_var($host, \FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (!self::isInternal($ip)) {
                throw new UnifiException(\sprintf('Por segurança o controller precisa estar na rede interna (10/8, 172.16/12, 192.168/16, 100.64/10); %s aponta para %s.', $host, $ip));
            }
        }
    }

    public static function isInternal(string $ip): bool
    {
        if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) && (ip2long($ip) & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
            return true; // CGNAT (links de operadora/VPN)
        }
        if (false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_RES_RANGE)) {
            return false; // loopback, link-local/metadata, 0.0.0.0, reservados
        }

        return false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE); // RFC 1918 / fc00::/7
    }
}
