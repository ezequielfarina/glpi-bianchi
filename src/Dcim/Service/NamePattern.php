<?php

declare(strict_types=1);

namespace App\Dcim\Service;

use App\Ui\Write\ValidationException;

/**
 * Expansão de nomes em lote: "Gi1/0/[1-48]" → Gi1/0/1 … Gi1/0/48; "PT-[1-3,7]"; "[a-c]".
 * Vários grupos se multiplicam: "[1-2]/[1-3]" → 1/1, 1/2, 1/3, 2/1…
 *
 * Porta de `expand_alphanumeric_pattern` (netbox/utilities/forms/utils.py). Zeros à esquerda
 * são preservados ("[01-12]" → 01…12).
 */
final class NamePattern
{
    public const int MAX = 1024;

    /** @return list<string> */
    public static function expand(string $pattern): array
    {
        if (!preg_match('/\[([^\]]+)\]/', $pattern, $m, \PREG_OFFSET_CAPTURE)) {
            return [$pattern];
        }
        $head = substr($pattern, 0, $m[0][1]);
        $tail = substr($pattern, $m[0][1] + \strlen($m[0][0]));
        $out = [];
        foreach (self::group($m[1][0]) as $value) {
            foreach (self::expand($tail) as $rest) {
                $out[] = $head.$value.$rest;
                if (\count($out) > self::MAX) {
                    throw new ValidationException(['name' => \sprintf('O padrão gera mais de %d nomes.', self::MAX)]);
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function group(string $spec): array
    {
        $values = [];
        foreach (explode(',', $spec) as $part) {
            $part = trim($part);
            if (preg_match('/^(\d+)-(\d+)$/', $part, $r)) {
                [$a, $b] = [(int) $r[1], (int) $r[2]];
                if ($a > $b || $b - $a >= self::MAX) {
                    throw new ValidationException(['name' => \sprintf('Intervalo inválido: [%s].', $part)]);
                }
                $pad = \strlen($r[1]) > 1 && str_starts_with($r[1], '0') ? \strlen($r[1]) : 0;
                for ($i = $a; $i <= $b; ++$i) {
                    $values[] = $pad > 0 ? str_pad((string) $i, $pad, '0', \STR_PAD_LEFT) : (string) $i;
                }
            } elseif (preg_match('/^([a-zA-Z])-([a-zA-Z])$/', $part, $r) && \ord($r[1]) <= \ord($r[2])) {
                foreach (range($r[1], $r[2]) as $c) {
                    $values[] = (string) $c;
                }
            } elseif ('' !== $part) {
                $values[] = $part;
            } else {
                throw new ValidationException(['name' => 'Padrão com grupo vazio.']);
            }
        }

        return $values;
    }
}
