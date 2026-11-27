<?php

declare(strict_types=1);

namespace App\Ipam\Service;

/**
 * Aritmética de endereços IPv4/IPv6 em binário (string de 4/16 bytes, big-endian), sem GMP.
 * Strings binárias do mesmo tamanho comparam com strcmp na ordem numérica.
 *
 * Usado no IPAM (ADR-0016 §7) para normalizar prefixos, achar o próximo IP livre e as lacunas
 * entre prefixos (equivalente ao netaddr.IPSet usado pelo NetBox).
 */
final class IpNet
{
    /**
     * "10.1.2.3/24" | "10.1.2.3" | "2001:db8::/32" → endereço (binário), tamanho do prefixo e família.
     *
     * @return array{bin: string, len: int, family: int, bits: int}|null
     */
    public static function parse(string $text, ?int $defaultLen = null): ?array
    {
        $text = trim($text);
        $len = null;
        if (str_contains($text, '/')) {
            [$text, $l] = explode('/', $text, 2);
            if (1 !== preg_match('/^\d{1,3}$/', $l)) {
                return null;
            }
            $len = (int) $l;
        }
        $bin = @inet_pton($text);
        if (false === $bin) {
            return null;
        }
        $bits = 4 === \strlen($bin) ? 32 : 128;
        $len ??= $defaultLen ?? $bits;
        if ($len < 0 || $len > $bits) {
            return null;
        }

        return ['bin' => $bin, 'len' => $len, 'family' => 32 === $bits ? 4 : 6, 'bits' => $bits];
    }

    /** Endereço da rede (bits de host zerados). */
    public static function network(string $bin, int $len): string
    {
        return $bin & self::mask(\strlen($bin), $len);
    }

    /** Último endereço do bloco (broadcast no IPv4). */
    public static function last(string $bin, int $len): string
    {
        return $bin | ~self::mask(\strlen($bin), $len);
    }

    public static function mask(int $bytes, int $len): string
    {
        $m = str_repeat("\xff", intdiv($len, 8));
        if (0 !== $len % 8) {
            $m .= \chr((0xFF << (8 - $len % 8)) & 0xFF);
        }

        return str_pad($m, $bytes, "\0");
    }

    public static function text(string $bin): string
    {
        return (string) inet_ntop($bin);
    }

    public static function cidr(string $bin, int $len): string
    {
        return self::text(self::network($bin, $len)).'/'.$len;
    }

    public static function inc(string $bin, int $by = 1): string
    {
        $bytes = array_values(unpack('C*', $bin) ?: []);
        for ($i = \count($bytes) - 1; $i >= 0 && $by > 0; --$i) {
            $sum = $bytes[$i] + ($by & 0xFF);
            $by = ($by >> 8) + ($sum >> 8);
            $bytes[$i] = $sum & 0xFF;
        }

        return pack('C*', ...$bytes);
    }

    public static function dec(string $bin): string
    {
        $bytes = array_values(unpack('C*', $bin) ?: []);
        for ($i = \count($bytes) - 1; $i >= 0; --$i) {
            if ($bytes[$i] > 0) {
                --$bytes[$i];
                break;
            }
            $bytes[$i] = 0xFF;
        }

        return pack('C*', ...$bytes);
    }

    public static function cmp(string $a, string $b): int
    {
        return strcmp($a, $b) <=> 0;
    }

    /** Quantidade de endereços do bloco (float para caber IPv6). */
    public static function size(int $bits, int $len): float
    {
        return 2.0 ** ($bits - $len);
    }

    /**
     * Endereços úteis de um prefixo (Prefix.get_available_ips do NetBox): no IPv4 tira rede e broadcast,
     * no IPv6 tira o anycast do roteador da sub-rede — exceto em pool e em ponto a ponto (/31, /127) ou host.
     *
     * @return array{0: string, 1: string} [primeiro, último]
     */
    public static function usable(string $network, int $len, bool $isPool): array
    {
        $bits = 4 === \strlen($network) ? 32 : 128;
        $first = $network;
        $last = self::last($network, $len);
        if ($isPool || $len >= $bits - 1) {
            return [$first, $last];
        }
        $first = self::inc($first);
        if (32 === $bits) {
            $last = self::dec($last);
        }

        return [$first, $last];
    }

    /**
     * Primeiro endereço livre entre [$first, $last] pulando os intervalos ocupados.
     *
     * @param list<array{0: string, 1: string}> $used intervalos [início, fim] em binário
     */
    public static function firstFree(string $first, string $last, array $used): ?string
    {
        usort($used, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $candidate = $first;
        foreach ($used as [$s, $e]) {
            if (strcmp($e, $candidate) < 0) {
                continue;
            }
            if (strcmp($s, $candidate) > 0) {
                break;
            }
            if (strcmp($e, $last) >= 0) {
                return null;
            }
            $candidate = self::inc($e);
        }

        return strcmp($candidate, $last) <= 0 ? $candidate : null;
    }

    /**
     * Lacunas IPv4 entre blocos ocupados, decompostas em CIDRs mínimos (Prefix.get_available_prefixes).
     *
     * @param list<array{0: int, 1: int}> $used intervalos [início, fim] inteiros
     *
     * @return list<string>
     */
    public static function freeCidrs4(int $start, int $end, array $used): array
    {
        usort($used, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];
        $cursor = $start;
        foreach ($used as [$s, $e]) {
            if ($e < $cursor) {
                continue;
            }
            if ($s > $cursor) {
                array_push($out, ...self::rangeToCidrs4($cursor, min($s - 1, $end)));
            }
            $cursor = max($cursor, $e + 1);
            if ($cursor > $end) {
                return $out;
            }
        }
        if ($cursor <= $end) {
            array_push($out, ...self::rangeToCidrs4($cursor, $end));
        }

        return $out;
    }

    /** @return list<string> */
    public static function rangeToCidrs4(int $start, int $end): array
    {
        $out = [];
        while ($start <= $end) {
            $maxSize = 32;
            while ($maxSize > 0) {
                $mask = (1 << (32 - ($maxSize - 1))) - 1;
                if (0 !== ($start & $mask)) {
                    break;
                }
                if ($start + $mask > $end) {
                    break;
                }
                --$maxSize;
            }
            $out[] = long2ip($start).'/'.$maxSize;
            $start += 1 << (32 - $maxSize);
        }

        return $out;
    }

    public static function v4int(string $bin): int
    {
        return (int) (unpack('N', $bin)[1] ?? 0);
    }
}
