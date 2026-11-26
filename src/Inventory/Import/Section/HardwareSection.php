<?php

declare(strict_types=1);

namespace App\Inventory\Import\Section;

use App\Asset\Entity\AssetModel;
use App\Asset\Repository\ManufacturerRepository;
use App\Inventory\Import\ImportContext;
use App\Inventory\Import\Value;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Campos principais do ativo a partir de `hardware` + `bios`.
 *
 *   hardware.name        → name
 *   hardware.uuid        → hardwareUuid
 *   bios.ssn | msn       → serialNumber   (system serial; fallback: motherboard serial)
 *   bios.smanufacturer   → manufacturer   (fallback: mmanufacturer)
 *   bios.smodel          → model          (fallback: mmodel)
 *   hardware.memory      → attributes.memory_mib
 *   hardware.chassis_type, workgroup, winowner... → attributes.*
 */
final readonly class HardwareSection implements SectionImporter
{
    public function __construct(
        private ManufacturerRepository $manufacturers,
        private EntityManagerInterface $em,
    ) {
    }

    public function sections(): array
    {
        return ['hardware', 'bios'];
    }

    public static function priority(): int
    {
        return 100;
    }

    public function import(array $content, ImportContext $context): void
    {
        $asset = $context->asset;
        $hw = \is_array($content['hardware'] ?? null) ? $content['hardware'] : [];
        $bios = \is_array($content['bios'] ?? null) ? $content['bios'] : [];

        if (null !== ($name = Value::str($hw['name'] ?? null))) {
            $context->apply('name', $asset->getName(), $name, $asset->setName(...));
        }
        if (null !== ($uuid = Value::str($hw['uuid'] ?? null, 64))) {
            $context->apply('hardwareUuid', $asset->getHardwareUuid(), strtolower($uuid), $asset->setHardwareUuid(...));
        }

        $serial = Value::str($bios['ssn'] ?? null) ?? Value::str($bios['msn'] ?? null);
        if (null !== $serial) {
            $context->apply('serialNumber', $asset->getSerialNumber(), $serial, $asset->setSerialNumber(...));
        }

        $manufacturer = $this->manufacturers->findOrCreate(
            Value::str($bios['smanufacturer'] ?? null) ?? Value::str($bios['mmanufacturer'] ?? null)
        );
        if (null !== $manufacturer) {
            $context->apply('manufacturer', $asset->getManufacturer(), $manufacturer, $asset->setManufacturer(...));
        }

        $modelName = Value::str($bios['smodel'] ?? null) ?? Value::str($bios['mmodel'] ?? null);
        if (null !== $modelName) {
            $model = $this->resolveModel($asset->getAssetType(), $manufacturer, $modelName);
            $context->apply('model', $asset->getModel(), $model, $asset->setModel(...));
        }

        $attributes = [
            'memory_mib' => Value::int($hw['memory'] ?? null),
            'swap_mib' => Value::int($hw['swap'] ?? null),
            'chassis_type' => Value::str($hw['chassis_type'] ?? null),
            'workgroup' => Value::str($hw['workgroup'] ?? null),
            'last_logged_user' => Value::str($hw['lastloggeduser'] ?? null),
            'bios_version' => Value::str($bios['bversion'] ?? null),
            'bios_date' => Value::str($bios['bdate'] ?? null),
            'bios_manufacturer' => Value::str($bios['bmanufacturer'] ?? null),
            'asset_tag' => Value::str($bios['assettag'] ?? null),
        ];
        foreach ($attributes as $key => $value) {
            $context->apply('attributes.'.$key, $asset->getAttribute($key), $value, static fn (mixed $v) => $asset->setAttribute($key, $v));
        }
    }

    private function resolveModel(\App\Asset\Entity\AssetType $type, ?\App\Asset\Entity\Manufacturer $manufacturer, string $name): AssetModel
    {
        $repo = $this->em->getRepository(AssetModel::class);
        $model = $repo->findOneBy(['assetType' => $type, 'manufacturer' => $manufacturer, 'name' => $name]);
        if (null === $model) {
            $model = new AssetModel($type, $name, $manufacturer);
            $this->em->persist($model);
        }

        return $model;
    }
}
