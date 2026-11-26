<?php

declare(strict_types=1);

namespace App\Asset\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Asset\Repository\ManufacturerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fabricante / editor de software. `normalizedName` é a chave de deduplicação
 * ("Dell Inc." e "DELL INC" → "dell inc"). Fase 2: dicionário de normalização
 * (equivalente ao RuleDictionnaryManufacturer do GLPI).
 */
#[ORM\Entity(repositoryClass: ManufacturerRepository::class)]
#[ORM\Table(name: 'manufacturer')]
#[ORM\UniqueConstraint(name: 'uniq_manufacturer_normalized', columns: ['normalized_name'])]
#[ApiResource(operations: [new Get(), new GetCollection()])]
class Manufacturer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $normalizedName;

    public function __construct(string $name)
    {
        $this->name = trim($name);
        $this->normalizedName = self::normalize($name);
    }

    public static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }
}
