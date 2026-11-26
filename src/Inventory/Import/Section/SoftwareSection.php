<?php

declare(strict_types=1);

namespace App\Inventory\Import\Section;

use App\Inventory\Import\ImportContext;
use App\Inventory\Import\Value;
use App\Software\Entity\SoftwareInstallation;
use App\Software\Repository\SoftwareCatalog;

/**
 * `softwares[]` → SoftwareInstallation (+ Software/SoftwareVersion no catálogo).
 *
 * Seção mais pesada do inventário Windows (centenas de itens). Diff em memória
 * por id de versão; instalações manuais (dynamic=false) nunca são removidas.
 * Fase 2: dicionário de software (ignorar KBs, renomear, agrupar editores).
 */
final readonly class SoftwareSection implements SectionImporter
{
    public function __construct(private SoftwareCatalog $catalog)
    {
    }

    public function sections(): array
    {
        return ['softwares'];
    }

    public static function priority(): int
    {
        return 40;
    }

    public function import(array $content, ImportContext $context): void
    {
        $asset = $context->asset;

        /** @var array<int|string, SoftwareInstallation> $existing chave: id da versão (ou spl_object_id se nova) */
        $existing = [];
        foreach ($asset->getSoftwareInstallations() as $installation) {
            $existing[self::versionKey($installation)] = $installation;
        }

        $seen = [];
        foreach (Value::list($content['softwares'] ?? null) as $sw) {
            $name = Value::str($sw['name'] ?? null);
            if (null === $name) {
                continue;
            }
            $version = $this->catalog->resolve(
                $name,
                Value::str($sw['publisher'] ?? null),
                Value::str($sw['version'] ?? null),
                Value::str($sw['arch'] ?? null, 32),
            );

            $key = null !== $version->getId() ? $version->getId() : 'new:'.spl_object_id($version);
            if (isset($seen[$key])) {
                continue; // duplicata no próprio payload (comum no registro do Windows)
            }
            $seen[$key] = true;

            if (isset($existing[$key])) {
                $existing[$key]->setInstalledAt(Value::date($sw['install_date'] ?? null) ?? $existing[$key]->getInstalledAt());
                unset($existing[$key]);
                continue;
            }

            $installation = new SoftwareInstallation($asset, $version);
            $installation->setDynamic(true);
            $installation->setInstalledAt(Value::date($sw['install_date'] ?? null));
            $asset->addSoftwareInstallation($installation);
            $context->event('software.installed', null, self::describe($installation));
        }

        foreach ($existing as $leftover) {
            if ($leftover->isDynamic()) {
                $asset->removeSoftwareInstallation($leftover);
                $context->event('software.removed', self::describe($leftover), null);
            }
        }
    }

    private static function versionKey(SoftwareInstallation $i): int|string
    {
        return $i->getSoftwareVersion()->getId() ?? 'new:'.spl_object_id($i->getSoftwareVersion());
    }

    private static function describe(SoftwareInstallation $i): string
    {
        $v = $i->getSoftwareVersion();

        return trim($v->getSoftware()->getName().' '.$v->getVersion().('' !== $v->getArchitecture() ? ' ('.$v->getArchitecture().')' : ''));
    }
}
