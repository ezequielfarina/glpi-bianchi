<?php

declare(strict_types=1);

namespace App\Inventory\Import;

use App\Asset\Entity\Asset;
use App\Asset\Entity\AssetType;
use App\Asset\Repository\AssetRepository;
use App\Inventory\Entity\Agent;

/**
 * Encontra o ativo existente correspondente a um inventário — versão simplificada
 * das "regras de importação e vinculação" do GLPI (RuleImportAsset), com ordem fixa:
 *
 *   1. hardware.uuid (SMBIOS)        — mais forte, sobrevive a troca de nome e reinstalação
 *   2. bios.ssn + tipo               — ignora seriais placeholder (ver Blacklist)
 *   3. ativo já vinculado ao agente  — mesmo deviceid, ex.: VM sem UUID estável
 *   4. hardware.name + tipo          — último recurso
 *
 * Fase 2: regras configuráveis (critérios, organização de destino, recusa) via tabela.
 */
final readonly class AssetMatcher
{
    public function __construct(private AssetRepository $assets)
    {
    }

    /** @param array<string, mixed> $content */
    public function match(array $content, AssetType $type, ?Agent $agent): ?Asset
    {
        $hw = \is_array($content['hardware'] ?? null) ? $content['hardware'] : [];
        $bios = \is_array($content['bios'] ?? null) ? $content['bios'] : [];

        if (null !== ($uuid = Blacklist::uuid(Value::str($hw['uuid'] ?? null, 64)))
            && null !== ($asset = $this->assets->findOneByHardwareUuid($uuid))) {
            return $asset;
        }

        $serial = Blacklist::serial(Value::str($bios['ssn'] ?? null)) ?? Blacklist::serial(Value::str($bios['msn'] ?? null));
        if (null !== $serial && null !== ($asset = $this->assets->findOneBySerial($serial, $type))) {
            return $asset;
        }

        $linked = $agent?->getAsset();
        if (null !== $linked && !$linked->isDeleted() && $linked->getAssetType() === $type) {
            return $linked;
        }

        if (null !== ($name = Value::str($hw['name'] ?? null))) {
            return $this->assets->findOneByName($name, $type);
        }

        return null;
    }
}
