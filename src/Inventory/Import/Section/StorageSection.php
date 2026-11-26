<?php

declare(strict_types=1);

namespace App\Inventory\Import\Section;

use App\Asset\Entity\Component;
use App\Asset\Enum\ComponentKind;
use App\Asset\Repository\ManufacturerRepository;
use App\Inventory\Import\ImportContext;
use App\Inventory\Import\Value;

/**
 * `storages[]` → Component(kind=storage) — discos físicos (HDD/SSD/NVMe).
 * Unidades removíveis/ópticas (type cdrom/dvd) vão como kind=drive.
 * Volumes/partições (`drives[]`) ficam para a fase 2 (tabela própria de filesystem).
 */
final readonly class StorageSection implements SectionImporter
{
    private const array OPTICAL = ['cdrom', 'dvd', 'dvd-rom', 'cd-rom', 'optical'];

    public function __construct(private ManufacturerRepository $manufacturers)
    {
    }

    public function sections(): array
    {
        return ['storages'];
    }

    public static function priority(): int
    {
        return 50;
    }

    public function import(array $content, ImportContext $context): void
    {
        $disks = [];
        $drives = [];
        foreach (Value::list($content['storages'] ?? null) as $s) {
            $type = mb_strtolower(Value::str($s['type'] ?? null) ?? '');
            $isOptical = \in_array($type, self::OPTICAL, true);
            $kind = $isOptical ? ComponentKind::Drive : ComponentKind::Storage;
            $designation = Value::str($s['model'] ?? null) ?? Value::str($s['name'] ?? null) ?? 'Disk';

            $c = new Component($context->asset, $kind, $designation);
            $c->setManufacturer($this->manufacturers->findOrCreate(Value::str($s['manufacturer'] ?? null)));
            $c->setSerialNumber(Value::str($s['serial'] ?? null));
            $c->setSlot(Value::str($s['name'] ?? null));
            $c->setCapacityMib($isOptical ? null : Value::int($s['disksize'] ?? null));
            $c->setAttributes([
                'interface' => Value::str($s['interface'] ?? null),
                'type' => Value::str($s['type'] ?? null),
                'firmware' => Value::str($s['firmware'] ?? null),
                'description' => Value::str($s['description'] ?? null),
            ]);

            if ($isOptical) {
                $drives[] = $c;
            } else {
                $disks[] = $c;
            }
        }

        ComponentSynchronizer::sync(ComponentKind::Storage, $disks, $context);
        ComponentSynchronizer::sync(ComponentKind::Drive, $drives, $context);
    }
}
