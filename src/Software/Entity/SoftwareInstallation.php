<?php

declare(strict_types=1);

namespace App\Software\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Asset\Entity\Asset;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Software instalado em um ativo (equivalente a glpi_items_softwareversions).
 * Costuma ser a maior tabela depois do histórico: ~300–1500 linhas por estação Windows.
 */
#[ORM\Entity]
#[ORM\Table(name: 'software_installation')]
#[ORM\UniqueConstraint(name: 'uniq_software_installation', columns: ['asset_id', 'software_version_id'])]
#[ORM\Index(name: 'idx_software_installation_version', columns: ['software_version_id'])]
#[ApiResource(operations: [new Get(), new GetCollection()])]
class SoftwareInstallation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'softwareInstallations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SoftwareVersion $softwareVersion;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $installedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $dynamic = false;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    public function __construct(Asset $asset, SoftwareVersion $softwareVersion)
    {
        $this->asset = $asset;
        $this->softwareVersion = $softwareVersion;
        $this->firstSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function getSoftwareVersion(): SoftwareVersion
    {
        return $this->softwareVersion;
    }

    public function getInstalledAt(): ?\DateTimeImmutable
    {
        return $this->installedAt;
    }

    public function setInstalledAt(?\DateTimeImmutable $installedAt): void
    {
        $this->installedAt = $installedAt;
    }

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    public function setDynamic(bool $dynamic): void
    {
        $this->dynamic = $dynamic;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }
}
