<?php

declare(strict_types=1);

namespace App\Dcim\Service;

/**
 * O pdo_pgsql devolve NUMERIC/float8 como string ("10", "1.5"). Converte as colunas numéricas das linhas
 * antes de virar JSON — a elevação do rack faz conta com posição e altura.
 */
final class Num
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $floats
     * @param list<string>               $ints
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $rows, array $floats, array $ints = []): array
    {
        return array_map(static fn (array $r): array => self::row($r, $floats, $ints), $rows);
    }

    /**
     * @param array<string, mixed> $r
     * @param list<string>         $floats
     * @param list<string>         $ints
     *
     * @return array<string, mixed>
     */
    public static function row(array $r, array $floats, array $ints = []): array
    {
        foreach ($floats as $k) {
            if (isset($r[$k]) && is_numeric($r[$k])) {
                $r[$k] = (float) $r[$k];
            }
        }
        foreach ($ints as $k) {
            if (isset($r[$k]) && is_numeric($r[$k])) {
                $r[$k] = (int) $r[$k];
            }
        }

        return $r;
    }
}
