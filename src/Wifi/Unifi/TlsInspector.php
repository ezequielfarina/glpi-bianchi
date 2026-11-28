<?php

declare(strict_types=1);

namespace App\Wifi\Unifi;

/**
 * Lê o certificado que o controller apresenta (sem confiar nele) para o administrador conferir e fixar:
 * pin-sha256 = base64(SHA-256 da chave pública SPKI), o mesmo formato do curl/HPKP — sobrevive à
 * renovação do certificado se a chave for mantida.
 */
final class TlsInspector
{
    /** @return array{subject: string, issuer: string, valid_to: string, self_signed: bool, sha256: string, pin: string} */
    public function inspect(string $baseUrl): array
    {
        $host = (string) parse_url($baseUrl, \PHP_URL_HOST);
        $port = (int) (parse_url($baseUrl, \PHP_URL_PORT) ?? 443);
        if ('' === $host || 'https' !== parse_url($baseUrl, \PHP_URL_SCHEME)) {
            throw new UnifiException('O endereço do controller precisa começar com https://');
        }
        NetworkGuard::assertInternal($baseUrl);
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 8, \STREAM_CLIENT_CONNECT, $ctx);
        if (false === $sock) {
            throw new UnifiException("Não conectou em $host:$port para ler o certificado: ".('' !== $errstr ? $errstr : 'sem resposta').'. Verifique IP/porta e firewall.');
        }
        $params = stream_context_get_params($sock);
        fclose($sock);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert instanceof \OpenSSLCertificate) {
            throw new UnifiException('O controller não apresentou certificado TLS.');
        }
        $info = openssl_x509_parse($cert) ?: [];
        $key = openssl_pkey_get_public($cert);
        $pem = false === $key ? '' : (string) (openssl_pkey_get_details($key)['key'] ?? '');
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
        if (false === $der || '' === $der) {
            throw new UnifiException('Não foi possível ler a chave pública do certificado.');
        }
        $fmt = static fn (array $dn): string => implode(', ', array_map(static fn ($k, $v) => $k.'='.(\is_array($v) ? implode('/', $v) : $v), array_keys($dn), $dn));
        openssl_x509_export($cert, $certPem);

        return [
            'subject' => $fmt((array) ($info['subject'] ?? [])),
            'issuer' => $fmt((array) ($info['issuer'] ?? [])),
            'valid_to' => isset($info['validTo_time_t']) ? date(\DATE_ATOM, (int) $info['validTo_time_t']) : '',
            'self_signed' => ($info['subject'] ?? null) === ($info['issuer'] ?? null),
            'sha256' => implode(':', str_split(strtoupper((string) openssl_x509_fingerprint($cert, 'sha256')), 2)),
            'pin' => base64_encode(hash('sha256', $der, true)),
        ];
    }
}
