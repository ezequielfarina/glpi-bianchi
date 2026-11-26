<?php

declare(strict_types=1);

namespace App\Inventory\Import\Section;

use App\Asset\Entity\Component;
use App\Asset\Enum\ComponentKind;
use App\Asset\Repository\ManufacturerRepository;
use App\Inventory\Import\ImportContext;
use App\Inventory\Import\Value;

/** `cpus[]` → Component(kind=processor). Uma linha por socket, como o agente reporta. */
final readonly class ProcessorSection implements SectionImporter
{
    public function __construct(private ManufacturerRepository $manufacturers)
    {
    }

    public function sections(): array
    {
        return ['cpus'];
    }

    public static function priority(): int
    {
        return 50;
    }

    public function import(array $content, ImportContext $context): void
    {
        $incoming = [];
        foreach (Value::list($content['cpus'] ?? null) as $i => $cpu) {
            $name = Value::str($cpu['name'] ?? null) ?? Value::str($cpu['model'] ?? null) ?? 'CPU';
            $c = new Component($context->asset, ComponentKind::Processor, $name);
            $c->setManufacturer($this->manufacturers->findOrCreate(Value::str($cpu['manufacturer'] ?? null)));
            $c->setSerialNumber(Value::str($cpu['serial'] ?? null));
            $c->setSlot('CPU'.$i);
            $c->setFrequencyMhz(Value::int($cpu['speed'] ?? null));
            $c->setAttributes([
                'cores' => Value::int($cpu['core'] ?? null),
                'threads' => Value::int($cpu['thread'] ?? null),
                'architecture' => Value::str($cpu['arch'] ?? null),
                'family' => Value::str($cpu['familyname'] ?? null),
                'external_clock_mhz' => Value::int($cpu['external_clock'] ?? null),
            ]);
            $incoming[] = $c;
        }

        ComponentSynchronizer::sync(ComponentKind::Processor, $incoming, $context);
    }
}
