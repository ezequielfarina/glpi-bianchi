<?php

declare(strict_types=1);

namespace App\Inventory\Import\Section;

use App\Asset\Entity\AssetModel;
use App\Asset\Entity\AssetType;
use App\Asset\Entity\Manufacturer;
use App\Asset\Repository\ManufacturerRepository;
use App\Inventory\Import\Blacklist;
use App\Inventory\Import\ImportContext;
use App\Inventory\Import\Value;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Campos principais do ativo a partir de `hardware` + `bios`.
 *
 *   hardware.name        → name
 *   hardware.uuid        → hardwareUuid    (UUIDs placeholder descartados: Blacklist)
 *   bios.ssn | msn       → serialNumber    (system serial; fallback: placa-mãe)
 *   bios.smanufacturer   → manufacturer    (fallback: mmanufacturer)
 *   bios.smodel          → model           (fallback: mmodel)
 *   hardware.* / bios.*  → attributes.*    (ver ATTRIBUTES)
 *
 * Regra de presença: campo AUSENTE do payload não altera nada (o agente omite
 * campos sem valor, p.ex. lastloggeduser quando roda como SYSTEM sem sessão).
 * Campo presente com valor vazio/placeholder também não apaga o valor anterior.
 */
final readonly class HardwareSection implements SectionImporter
{
    /** atributo => [seção, chave no payload, tipo] */
    private const array ATTRIBUTES = [
        'memory_mib' => ['hardware', 'memory', 'int'],
        'swap_mib' => ['hardware', 'swap', 'int'],
        'chassis_type' => ['hardware', 'chassis_type', 'str'],
        'workgroup' => ['hardware', 'workgroup', 'str'],
        'last_logged_user' => ['hardware', 'lastloggeduser', 'str'],
        'last_logged_user_at' => ['hardware', 'datelastloggeduser', 'str'],
        'windows_owner' => ['hardware', 'winowner', 'str'],
        'windows_company' => ['hardware', 'wincompany', 'str'],
        'windows_product_id' => ['hardware', 'winprodid', 'str'],
        'vm_system' => ['hardware', 'vmsystem', 'str'],
        'bios_version' => ['bios', 'bversion', 'str'],
        'bios_date' => ['bios', 'bdate', 'str'],
        'bios_manufacturer' => ['bios', 'bmanufacturer', 'str'],
        'motherboard_model' => ['bios', 'mmodel', 'str'],
        'motherboard_serial' => ['bios', 'msn', 'str'],
        'asset_tag' => ['bios', 'assettag', 'str'],
        'sku' => ['bios', 'skunumber', 'str'],
        'secure_boot' => ['bios', 'secure_boot', 'str'],
    ];

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
        $sections = [
            'hardware' => \is_array($content['hardware'] ?? null) ? $content['hardware'] : [],
            'bios' => \is_array($content['bios'] ?? null) ? $content['bios'] : [],
        ];
        $hw = $sections['hardware'];
        $bios = $sections['bios'];

        if (null !== ($name = Value::str($hw['name'] ?? null))) {
            $context->apply('name', $asset->getName(), $name, $asset->setName(...));
        }
        if (null !== ($uuid = Blacklist::uuid(Value::str($hw['uuid'] ?? null, 64)))) {
            $context->apply('hardwareUuid', $asset->getHardwareUuid(), $uuid, $asset->setHardwareUuid(...));
        }

        $serial = Blacklist::serial(Value::str($bios['ssn'] ?? null)) ?? Blacklist::serial(Value::str($bios['msn'] ?? null));
        if (null !== $serial) {
            $context->apply('serialNumber', $asset->getSerialNumber(), $serial, $asset->setSerialNumber(...));
        }

        $manufacturer = $this->manufacturers->findOrCreate(
            Blacklist::manufacturer(Value::str($bios['smanufacturer'] ?? null))
            ?? Blacklist::manufacturer(Value::str($bios['mmanufacturer'] ?? null))
        );
        if (null !== $manufacturer) {
            $context->apply('manufacturer', $asset->getManufacturer(), $manufacturer, $asset->setManufacturer(...));
        }

        $modelName = Blacklist::model(Value::str($bios['smodel'] ?? null)) ?? Blacklist::model(Value::str($bios['mmodel'] ?? null));
        if (null !== $modelName) {
            $model = $this->resolveModel($asset->getAssetType(), $manufacturer, $modelName);
            $context->apply('model', $asset->getModel(), $model, $asset->setModel(...));
        }

        foreach (self::ATTRIBUTES as $key => [$section, $field, $type]) {
            if (!\array_key_exists($field, $sections[$section])) {
                continue; // ausente → mantém
            }
            $value = 'int' === $type ? Value::int($sections[$section][$field]) : Value::str($sections[$section][$field]);
            if (null === $value) {
                continue; // vazio/placeholder → mantém
            }
            $context->apply('attributes.'.$key, $asset->getAttribute($key), $value, static fn (mixed $v) => $asset->setAttribute($key, $v));
        }
    }

    private function resolveModel(AssetType $type, ?Manufacturer $manufacturer, string $name): AssetModel
    {
        $model = $this->em->getRepository(AssetModel::class)->findOneBy(['assetType' => $type, 'manufacturer' => $manufacturer, 'name' => $name]);
        if (null === $model) {
            $model = new AssetModel($type, $name, $manufacturer);
            $this->em->persist($model);
        }

        return $model;
    }
}
