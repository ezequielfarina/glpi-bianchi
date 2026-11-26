<?php

declare(strict_types=1);

namespace App\Asset\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Asset\Enum\ComponentKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Componente de hardware de um ativo (CPU, pente de memória, disco, GPU...).
 *
 * Unidades normalizadas: capacidade em MiB, frequência em MHz.
 * Campos específicos do tipo (cores, threads, interface, firmware...) em `attributes`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'component')]
#[ORM\Index(name: 'idx_component_asset_kind', columns: ['asset_id', 'kind'])]
#[ORM\Index(name: 'idx_component_serial', columns: ['serial_number'])]
#[ApiResource(operations: [new Get(), new GetCollection()])]
class Component
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'components')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    #[ORM\Column(length: 32, enumType: ComponentKind::class)]
    private ComponentKind $kind;

    #[ORM\Column(length: 255)]
    private string $designation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Manufacturer $manufacturer = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $serialNumber = null;

    /** Slot / bank / device path (ex.: "DIMM_A1", "/dev/nvme0n1"). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $slot = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $capacityMib = null;

    #[ORM\Column(nullable: true)]
    private ?int $frequencyMhz = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $attributes = [];

    /** Vindo do inventário (true) → pode ser removido quando sumir do próximo inventário. */
    #[ORM\Column(options: ['default' => false])]
    private bool $dynamic = false;

    public function __construct(Asset $asset, ComponentKind $kind, string $designation)
    {
        $this->asset = $asset;
        $this->kind = $kind;
        $this->designation = $designation;
    }

    /**
     * Chave de reconciliação entre inventários sucessivos: serial quando existe,
     * senão designação + slot. Mesma estratégia dos handlers do GLPI.
     */
    public function reconciliationKey(): string
    {
        if (null !== $this->serialNumber && '' !== $this->serialNumber) {
            return $this->kind->value.'|sn|'.mb_strtolower($this->serialNumber);
        }

        return $this->kind->value.'|'.mb_strtolower($this->designation).'|'.mb_strtolower($this->slot ?? '');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function getKind(): ComponentKind
    {
        return $this->kind;
    }

    public function getDesignation(): string
    {
        return $this->designation;
    }

    public function getManufacturer(): ?Manufacturer
    {
        return $this->manufacturer;
    }

    public function setManufacturer(?Manufacturer $manufacturer): void
    {
        $this->manufacturer = $manufacturer;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(?string $serialNumber): void
    {
        $this->serialNumber = $serialNumber;
    }

    public function getSlot(): ?string
    {
        return $this->slot;
    }

    public function setSlot(?string $slot): void
    {
        $this->slot = $slot;
    }

    public function getCapacityMib(): ?int
    {
        return null === $this->capacityMib ? null : (int) $this->capacityMib;
    }

    public function setCapacityMib(?int $capacityMib): void
    {
        $this->capacityMib = null === $capacityMib ? null : (string) $capacityMib;
    }

    public function getFrequencyMhz(): ?int
    {
        return $this->frequencyMhz;
    }

    public function setFrequencyMhz(?int $frequencyMhz): void
    {
        $this->frequencyMhz = $frequencyMhz;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, mixed> $attributes */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = array_filter($attributes, static fn (mixed $v): bool => null !== $v && '' !== $v);
    }

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    public function setDynamic(bool $dynamic): void
    {
        $this->dynamic = $dynamic;
    }
}
