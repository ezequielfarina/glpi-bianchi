<?php

declare(strict_types=1);

namespace App\Inventory\Import\Section;

use App\Asset\Entity\Component;
use App\Asset\Enum\ComponentKind;
use App\Inventory\Import\ImportContext;

/**
 * Diff entre componentes existentes (de um `kind`) e os do inventário.
 *
 *  - casa por Component::reconciliationKey() (serial, ou designação+slot)
 *  - atualiza capacidade/frequência/atributos dos que casaram
 *  - adiciona novos (dynamic = true)
 *  - remove apenas os DINÂMICOS que sumiram — componente cadastrado à mão nunca é removido
 */
final class ComponentSynchronizer
{
    /**
     * @param list<Component> $incoming componentes montados a partir do inventário (ainda não persistidos)
     */
    public static function sync(ComponentKind $kind, array $incoming, ImportContext $context): void
    {
        $asset = $context->asset;

        $existing = [];
        foreach ($asset->getComponents() as $component) {
            if ($component->getKind() === $kind) {
                $existing[$component->reconciliationKey()][] = $component;
            }
        }

        foreach ($incoming as $new) {
            $key = $new->reconciliationKey();
            $match = isset($existing[$key]) ? array_shift($existing[$key]) : null;
            if ([] === ($existing[$key] ?? null)) {
                unset($existing[$key]);
            }

            if (null === $match) {
                $new->setDynamic(true);
                $asset->addComponent($new);
                $context->event('component.added', null, self::describe($new));
                continue;
            }

            if ($match->getCapacityMib() !== $new->getCapacityMib()) {
                $context->event('component.'.$kind->value.'.capacity', (string) $match->getCapacityMib(), (string) $new->getCapacityMib());
                $match->setCapacityMib($new->getCapacityMib());
            }
            $match->setFrequencyMhz($new->getFrequencyMhz());
            $match->setManufacturer($new->getManufacturer());
            $match->setAttributes($new->getAttributes());
        }

        foreach ($existing as $leftovers) {
            foreach ($leftovers as $old) {
                if ($old->isDynamic()) {
                    $asset->removeComponent($old);
                    $context->event('component.removed', self::describe($old), null);
                }
            }
        }
    }

    private static function describe(Component $c): string
    {
        return \sprintf(
            '%s: %s%s%s',
            $c->getKind()->value,
            $c->getDesignation(),
            null !== $c->getCapacityMib() ? ' ('.$c->getCapacityMib().' MiB)' : '',
            null !== $c->getSerialNumber() ? ' S/N '.$c->getSerialNumber() : '',
        );
    }
}
