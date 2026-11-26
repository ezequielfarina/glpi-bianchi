<?php

declare(strict_types=1);

namespace App\Inventory\Import;

/**
 * Normalização dos valores vindos do agente: strings vazias, placeholders de
 * fabricante ("To be filled by O.E.M.") e números em string.
 */
final class Value
{
    /** Placeholders genéricos. Seriais/UUIDs/modelos inválidos: ver Blacklist. */
    private const array JUNK = [
        '', 'n/a', 'na', 'none', 'null', 'unknown', 'not specified', 'not available',
        'to be filled by o.e.m.', 'default string',
    ];

    public static function str(mixed $value, int $maxLength = 255): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }
        $s = trim((string) $value);
        if (\in_array(mb_strtolower($s), self::JUNK, true)) {
            return null;
        }

        return mb_substr($s, 0, $maxLength);
    }

    public static function int(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && is_numeric(trim($value))) {
            return (int) round((float) trim($value));
        }
        if (\is_float($value)) {
            return (int) round($value);
        }

        return null;
    }

    public static function date(mixed $value): ?\DateTimeImmutable
    {
        $s = self::str($value, 64);
        if (null === $s) {
            return null;
        }
        // formatos vistos nos agentes: "2026-11-26", "26/11/2026", "2026-11-26 10:00:00"
        foreach (['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y', 'm/d/Y'] as $format) {
            $d = \DateTimeImmutable::createFromFormat('!'.$format, $s);
            if (false !== $d) {
                return $d;
            }
        }

        return null;
    }

    /**
     * Seção do inventário como lista de objetos (o agente às vezes manda objeto único).
     *
     * @return list<array<string, mixed>>
     */
    public static function list(mixed $section): array
    {
        if (!\is_array($section) || [] === $section) {
            return [];
        }
        if (!array_is_list($section)) {
            $section = [$section];
        }

        return array_values(array_filter($section, 'is_array'));
    }
}
