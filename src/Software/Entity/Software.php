<?php

declare(strict_types=1);

namespace App\Software\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Asset\Entity\Manufacturer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Produto de software (catálogo). Deduplicado por `normalizedKey` = nome + editor.
 * Fase 2: dicionário de software (renomear/ignorar/agrupar) e licenças.
 */
#[ORM\Entity]
#[ORM\Table(name: 'software')]
#[ORM\UniqueConstraint(name: 'uniq_software_key', columns: ['normalized_key'])]
#[ORM\Index(name: 'idx_software_name', columns: ['name'])]
#[ApiResource(operations: [new Get(), new GetCollection()])]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'ipartial', 'publisher' => 'exact'])]
class Software
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Manufacturer $publisher;

    #[ORM\Column(length: 512)]
    private string $normalizedKey;

    /** @var Collection<int, SoftwareVersion> */
    #[ORM\OneToMany(targetEntity: SoftwareVersion::class, mappedBy: 'software')]
    private Collection $versions;

    public function __construct(string $name, ?Manufacturer $publisher = null)
    {
        $this->name = trim($name);
        $this->publisher = $publisher;
        $this->normalizedKey = self::key($name, $publisher?->getNormalizedName());
        $this->versions = new ArrayCollection();
    }

    public static function key(string $name, ?string $normalizedPublisher): string
    {
        return mb_strtolower(trim($name)).'|'.($normalizedPublisher ?? '');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPublisher(): ?Manufacturer
    {
        return $this->publisher;
    }

    public function getNormalizedKey(): string
    {
        return $this->normalizedKey;
    }

    /** @return Collection<int, SoftwareVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }
}
