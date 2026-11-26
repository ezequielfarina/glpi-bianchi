<?php

declare(strict_types=1);

namespace App\Software\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Versão + arquitetura de um software. `architecture` usa '' (não NULL) para que
 * a UNIQUE funcione sem NULLS NOT DISTINCT e o upsert seja determinístico.
 */
#[ORM\Entity]
#[ORM\Table(name: 'software_version')]
#[ORM\UniqueConstraint(name: 'uniq_software_version', columns: ['software_id', 'version', 'architecture'])]
#[ApiResource(operations: [new Get(), new GetCollection()])]
class SoftwareVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Software $software;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    private string $version;

    #[ORM\Column(length: 32, options: ['default' => ''])]
    private string $architecture;

    public function __construct(Software $software, ?string $version, ?string $architecture)
    {
        $this->software = $software;
        $this->version = trim($version ?? '');
        $this->architecture = trim($architecture ?? '');
    }

    public static function key(string $softwareKey, ?string $version, ?string $architecture): string
    {
        return $softwareKey.'|'.trim($version ?? '').'|'.trim($architecture ?? '');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSoftware(): Software
    {
        return $this->software;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getArchitecture(): string
    {
        return $this->architecture;
    }
}
