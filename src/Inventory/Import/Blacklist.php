<?php

declare(strict_types=1);

namespace App\Inventory\Import;

/**
 * Valores que fabricantes/agentes enviam como placeholder e NÃO podem identificar
 * um ativo (senão máquinas diferentes seriam fundidas no matching).
 *
 * Portado dos padrões do GLPI: src/Blacklist.php::getDefaults() (GPLv3).
 * Fase 2: tornar editável via tabela, como no GLPI.
 */
final class Blacklist
{
    /** Comparação case-insensitive, valor exato. */
    private const array SERIALS = [
        'n/a', '(null string)', 'invalid', 'sys-1234567890', 'sys-9876543210', 'sn-12345',
        'sn-1234567890', '0123456789', '12345', '123456', '1234567', '12345678', '123456789',
        '1234567890', '123456789000', '12345678901234567', 'nnnnnnn', 'xxxxxxxxxxx', 'eval',
        'iatpass', 'none', 'to be filled by o.e.m.', 'tulip computers', 'serial number xxxxxx',
        'sn-123456fvgv3i0b8o5n6n7k', 'unknow', 'unknown', 'system serial number', 'mb-1234567890',
        'empty', 'not specified', 'oem_serial', 'systemserialnumb', 'default string', 'chassis serial number',
    ];

    /** Regex adicionais do GLPI para seriais: só zeros/uns, ou versão "1.2.3". */
    private const array SERIAL_PATTERNS = ['/^0+$/', '/^1+$/', '/^(0|1)+$/', '/\d\.\d(\.\d)?/'];

    private const array UUIDS = [
        'ffffffff-ffff-ffff-ffff-ffffffffffff',
        '00000000-0000-0000-0000-000000000000',
        '03000200-0400-0500-0006-000700080009',
        '6ab5b300-538d-1014-9fb5-b0684d007b53',
        '01010101-0101-0101-0101-010101010101',
        '2',
    ];

    private const array MODELS = [
        'unknow', 'unknown', 'to be filled by o.e.m.', '*', 'system product name', 'product name',
        'system name', 'all series', 'default string',
    ];

    private const array MANUFACTURERS = [
        'system manufacturer', 'to be filled by o.e.m.', 'default string', 'unknown',
    ];

    public static function isInvalidSerial(?string $value): bool
    {
        if (null === $value || '' === trim($value)) {
            return true;
        }
        $v = mb_strtolower(trim($value));
        if (\in_array($v, self::SERIALS, true)) {
            return true;
        }
        foreach (self::SERIAL_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $v)) {
                return true;
            }
        }

        return false;
    }

    public static function isInvalidUuid(?string $value): bool
    {
        return null === $value || '' === trim($value) || \in_array(mb_strtolower(trim($value)), self::UUIDS, true);
    }

    public static function isInvalidModel(?string $value): bool
    {
        return null === $value || \in_array(mb_strtolower(trim($value)), self::MODELS, true);
    }

    public static function isInvalidManufacturer(?string $value): bool
    {
        return null === $value || \in_array(mb_strtolower(trim($value)), self::MANUFACTURERS, true);
    }

    public static function serial(?string $value): ?string
    {
        return self::isInvalidSerial($value) ? null : trim((string) $value);
    }

    public static function uuid(?string $value): ?string
    {
        return self::isInvalidUuid($value) ? null : strtolower(trim((string) $value));
    }

    public static function model(?string $value): ?string
    {
        return self::isInvalidModel($value) ? null : trim((string) $value);
    }

    public static function manufacturer(?string $value): ?string
    {
        return self::isInvalidManufacturer($value) ? null : trim((string) $value);
    }
}
