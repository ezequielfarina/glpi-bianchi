<?php

declare(strict_types=1);

namespace App\Inventory\Import\Section;

use App\Asset\Entity\OperatingSystem;
use App\Inventory\Import\ImportContext;
use App\Inventory\Import\Value;

/** `operatingsystem` → Asset::operatingSystem (embedded, colunas os_*). */
final readonly class OperatingSystemSection implements SectionImporter
{
    public function sections(): array
    {
        return ['operatingsystem'];
    }

    public static function priority(): int
    {
        return 90;
    }

    public function import(array $content, ImportContext $context): void
    {
        $os = \is_array($content['operatingsystem'] ?? null) ? $content['operatingsystem'] : [];
        $asset = $context->asset;

        $new = new OperatingSystem(
            name: Value::str($os['name'] ?? null),
            fullName: Value::str($os['full_name'] ?? null),
            version: Value::str($os['version'] ?? null, 128),
            servicePack: Value::str($os['service_pack'] ?? null, 64),
            architecture: Value::str($os['arch'] ?? null, 32),
            kernelVersion: Value::str($os['kernel_version'] ?? null),
            installedAt: Value::date($os['install_date'] ?? null),
        );

        if ($asset->getOperatingSystem()->equals($new)) {
            return;
        }
        $context->apply('operatingSystem', $asset->getOperatingSystem()->fingerprint(), $new->fingerprint(), static fn () => $asset->setOperatingSystem($new));
    }
}
