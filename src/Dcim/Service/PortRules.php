<?php

declare(strict_types=1);

namespace App\Dcim\Service;

use App\Ui\Write\Payload;

/** Validação comum de porta e de modelo de porta (tipo físico por tipo de porta, posições, consumo). */
final class PortRules
{
    /**
     * @return array{type: string|null, label: string|null, positions: int, mgmt_only: bool, maximum_draw: int|null, allocated_draw: int|null, description: string|null}
     */
    public static function read(Payload $p, string $kind, bool $partial = false): array
    {
        $types = DcimChoices::PORT_TYPES[$kind] ?? [];
        $type = $p->string('type', 32, required: !$partial);
        if (null !== $type && !isset($types[$type])) {
            $p->error('type', 'Tipo físico inválido para este tipo de porta.');
        }
        $positions = $p->int('positions', 1, 1024) ?? 1;
        if ('rear' !== $kind && $positions > 1) {
            $p->error('positions', 'Só a porta traseira tem várias posições.');
        }
        $max = $p->int('maximum_draw', 1, 100_000);
        $alloc = $p->int('allocated_draw', 1, 100_000);
        if ('power_port' !== $kind && (null !== $max || null !== $alloc)) {
            $p->error('maximum_draw', 'Consumo só se aplica à entrada de energia.');
        }
        if (null !== $max && null !== $alloc && $alloc > $max) {
            $p->error('allocated_draw', 'O consumo alocado não pode passar do máximo.');
        }

        return [
            'type' => $type,
            'label' => $p->string('label', 64),
            'positions' => $positions,
            'mgmt_only' => 'interface' === $kind && $p->bool('mgmt_only'),
            'maximum_draw' => $max,
            'allocated_draw' => $alloc,
            'description' => $p->string('description', 255),
        ];
    }

    public static function mac(Payload $p): ?string
    {
        $v = $p->string('mac_address', 32);
        if (null === $v) {
            return null;
        }
        $hex = strtolower((string) preg_replace('/[^0-9a-f]/i', '', $v));
        if (12 !== \strlen($hex)) {
            $p->error('mac_address', 'MAC inválido (ex.: 00:11:22:33:44:55).');

            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    /** Ordenação natural por tipo e nome (Gi1/0/2 antes de Gi1/0/10). @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    public static function sort(array $rows): array
    {
        $order = array_flip(array_keys(DcimChoices::PORT_KINDS));
        usort($rows, static fn (array $a, array $b): int => [$order[$a['kind']] ?? 99, 0] <=> [$order[$b['kind']] ?? 99, 0]
            ?: strnatcasecmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }
}
