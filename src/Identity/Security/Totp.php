<?php

declare(strict_types=1);

namespace App\Identity\Security;

/**
 * TOTP (RFC 6238) — compatível com Microsoft/Google Authenticator, Authy, FortiToken Mobile, 1Password.
 *
 * HMAC-SHA1, 6 dígitos, passo de 30 s, tolerância de ±1 passo (relógio do celular).
 * O passo aceito é devolvido para ser gravado em `totp_last_step`: um código
 * já usado (ou anterior) é recusado — impede replay de código interceptado.
 */
final class Totp
{
    public const int PERIOD = 30;
    public const int DIGITS = 6;
    private const string B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Segredo de 160 bits (recomendação da RFC 4226), em base32. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function uri(string $secret, string $account, string $issuer = 'GLPI Bianchi'): string
    {
        return \sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer), self::DIGITS, self::PERIOD,
        );
    }

    /**
     * @return int|null passo aceito (para anti-replay) ou null se inválido
     */
    public static function verify(#[\SensitiveParameter] string $secret, string $code, ?int $lastStep, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (1 !== preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $key = self::base32Decode($secret);
        $step = intdiv($now ?? time(), self::PERIOD);
        $match = null;
        // percorre a janela inteira (tempo constante em relação a qual passo casou)
        foreach ([-1, 0, 1] as $offset) {
            $candidate = $step + $offset;
            if (hash_equals(self::code($key, $candidate), $code) && (null === $lastStep || $candidate > $lastStep)) {
                $match ??= $candidate;
            }
        }

        return $match;
    }

    /** Código de um passo (exposto para testes). */
    public static function at(string $secret, int $timestamp): string
    {
        return self::code(self::base32Decode($secret), intdiv($timestamp, self::PERIOD));
    }

    private static function code(string $key, int $step): string
    {
        $hmac = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = \ord($hmac[19]) & 0x0F;
        $bin = ((\ord($hmac[$offset]) & 0x7F) << 24) | (\ord($hmac[$offset + 1]) << 16) | (\ord($hmac[$offset + 2]) << 8) | \ord($hmac[$offset + 3]);

        return str_pad((string) ($bin % 10 ** self::DIGITS), self::DIGITS, '0', \STR_PAD_LEFT);
    }

    private static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(\ord($c)), 8, '0', \STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim(preg_replace('/\s+/', '', $b32) ?? '', '='));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $v = strpos(self::B32, $c);
            if (false === $v) {
                throw new \InvalidArgumentException('Segredo TOTP inválido.');
            }
            $bits .= str_pad(decbin($v), 5, '0', \STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (8 === \strlen($byte)) {
                $out .= \chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
