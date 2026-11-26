<?php

declare(strict_types=1);

namespace App\Inventory\Import\Section;

use App\Asset\Entity\Component;
use App\Asset\Enum\ComponentKind;
use App\Asset\Repository\ManufacturerRepository;
use App\Inventory\Import\ImportContext;
use App\Inventory\Import\Value;

/**
 * `memories[]` → Component(kind=memory). Slots vazios (capacity 0/ausente) são ignorados,
 * mesmo comportamento do handler Memory do GLPI.
 */
final readonly class MemorySection implements SectionImporter
{
    public function __construct(private ManufacturerRepository $manufacturers)
    {
    }

    public function sections(): array
    {
        return ['memories'];
    }

    public static function priority(): int
    {
        return 50;
    }

    public function import(array $content, ImportContext $context): void
    {
        $incoming = [];
        foreach (Value::list($content['memories'] ?? null) as $mem) {
            $capacity = Value::int($mem['capacity'] ?? null);
            if (null === $capacity || $capacity <= 0) {
                continue;
            }
            $type = Value::str($mem['type'] ?? null);
            $designation = trim(($type ?? 'RAM').' '.(Value::str($mem['description'] ?? null) ?? ''));

            $c = new Component($context->asset, ComponentKind::Memory, $designation);
            $c->setManufacturer($this->manufacturers->findOrCreate(Value::str($mem['manufacturer'] ?? null)));
            $c->setSerialNumber(Value::str($mem['serialnumber'] ?? null));
            $c->setSlot(Value::str($mem['numslots'] ?? null) ?? Value::str($mem['caption'] ?? null));
            $c->setCapacityMib($capacity);
            $c->setFrequencyMhz(Value::int($mem['speed'] ?? null));
            $c->setAttributes([
                'type' => $type,
                'model' => Value::str($mem['model'] ?? null),
            ]);
            $incoming[] = $c;
        }

        ComponentSynchronizer::sync(ComponentKind::Memory, $incoming, $context);
    }
}
