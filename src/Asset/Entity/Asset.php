<?php

declare(strict_types=1);

namespace App\Asset\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Asset\Enum\AssetStatus;
use App\Asset\Repository\AssetRepository;
use App\Identity\Entity\Group;
use App\Identity\Entity\User;
use App\Network\Entity\NetworkPort;
use App\Organization\Entity\Location;
use App\Organization\Entity\Organization;
use App\Shared\Entity\TimestampableTrait;
use App\Software\Entity\SoftwareInstallation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ativo de TI — tabela única para todos os tipos (computador, switch, impressora...).
 *
 * Campos comuns são colunas; campos específicos do tipo ficam em `attributes`
 * (JSONB, indexado com GIN). Ver ADR-0003 para a justificativa de não replicar
 * o modelo "uma tabela por tipo" do GLPI (glpi_computers, glpi_printers...).
 *
 * Chaves de reconciliação do inventário (ordem de precedência):
 *   hardware_uuid → serial_number (+ tipo) → agente vinculado → name (+ tipo)
 */
#[ORM\Entity(repositoryClass: AssetRepository::class)]
#[ORM\Table(name: 'asset')]
#[ORM\UniqueConstraint(name: 'uniq_asset_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_asset_hardware_uuid', columns: ['hardware_uuid'])]
#[ORM\Index(name: 'idx_asset_serial', columns: ['serial_number'])]
#[ORM\Index(name: 'idx_asset_name', columns: ['name'])]
#[ORM\Index(name: 'idx_asset_org_type', columns: ['organization_id', 'asset_type_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [new Get(), new GetCollection(), new Post(), new Patch()],
    order: ['name' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'ipartial',
    'serialNumber' => 'exact',
    'inventoryNumber' => 'exact',
    'hardwareUuid' => 'exact',
    'assetType.code' => 'exact',
    'organization' => 'exact',
    'location' => 'exact',
    'status' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['lastInventoryAt', 'updatedAt'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'lastInventoryAt', 'updatedAt'])]
class Asset
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identificador público estável (UUIDv7) — use este em integrações. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Organization $organization;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private AssetType $assetType;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 32, enumType: AssetStatus::class, options: ['default' => 'in_use'])]
    private AssetStatus $status = AssetStatus::InUse;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Manufacturer $manufacturer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AssetModel $model = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $location = null;

    /** Usuário que utiliza o ativo. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Grupo tecnicamente responsável. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Group $techGroup = null;

    /** Número de série do fabricante (BIOS / chassis). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $serialNumber = null;

    /** Patrimônio / etiqueta interna ("otherserial" no GLPI). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $inventoryNumber = null;

    /** UUID SMBIOS informado pelo agente — chave de reconciliação mais forte. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hardwareUuid = null;

    #[ORM\Embedded(class: OperatingSystem::class, columnPrefix: 'os_')]
    private OperatingSystem $operatingSystem;

    /**
     * Atributos específicos do tipo (ex.: computer.memory_mib, printer.page_counter).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $attributes = [];

    /** Criado/atualizado pelo inventário automático (false = cadastro manual). */
    #[ORM\Column(options: ['default' => false])]
    private bool $dynamic = false;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastInventoryAt = null;

    /** Soft delete (lixeira), como no GLPI. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** @var Collection<int, Component> */
    #[ORM\OneToMany(targetEntity: Component::class, mappedBy: 'asset', cascade: ['persist'], orphanRemoval: true)]
    private Collection $components;

    /** @var Collection<int, SoftwareInstallation> */
    #[ORM\OneToMany(targetEntity: SoftwareInstallation::class, mappedBy: 'asset', cascade: ['persist'], orphanRemoval: true)]
    private Collection $softwareInstallations;

    /** @var Collection<int, NetworkPort> */
    #[ORM\OneToMany(targetEntity: NetworkPort::class, mappedBy: 'asset', cascade: ['persist'], orphanRemoval: true)]
    private Collection $networkPorts;

    public function __construct(Organization $organization, AssetType $assetType, string $name)
    {
        $this->uuid = Uuid::v7();
        $this->organization = $organization;
        $this->assetType = $assetType;
        $this->name = $name;
        $this->operatingSystem = new OperatingSystem();
        $this->components = new ArrayCollection();
        $this->softwareInstallations = new ArrayCollection();
        $this->networkPorts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function setOrganization(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function getAssetType(): AssetType
    {
        return $this->assetType;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getStatus(): AssetStatus
    {
        return $this->status;
    }

    public function setStatus(AssetStatus $status): void
    {
        $this->status = $status;
    }

    public function getManufacturer(): ?Manufacturer
    {
        return $this->manufacturer;
    }

    public function setManufacturer(?Manufacturer $manufacturer): void
    {
        $this->manufacturer = $manufacturer;
    }

    public function getModel(): ?AssetModel
    {
        return $this->model;
    }

    public function setModel(?AssetModel $model): void
    {
        $this->model = $model;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function setLocation(?Location $location): void
    {
        $this->location = $location;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function getTechGroup(): ?Group
    {
        return $this->techGroup;
    }

    public function setTechGroup(?Group $techGroup): void
    {
        $this->techGroup = $techGroup;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(?string $serialNumber): void
    {
        $this->serialNumber = $serialNumber;
    }

    public function getInventoryNumber(): ?string
    {
        return $this->inventoryNumber;
    }

    public function setInventoryNumber(?string $inventoryNumber): void
    {
        $this->inventoryNumber = $inventoryNumber;
    }

    public function getHardwareUuid(): ?string
    {
        return $this->hardwareUuid;
    }

    public function setHardwareUuid(?string $hardwareUuid): void
    {
        $this->hardwareUuid = null === $hardwareUuid ? null : strtolower($hardwareUuid);
    }

    public function getOperatingSystem(): OperatingSystem
    {
        return $this->operatingSystem;
    }

    public function setOperatingSystem(OperatingSystem $operatingSystem): void
    {
        $this->operatingSystem = $operatingSystem;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        if (null === $value) {
            unset($this->attributes[$key]);

            return;
        }
        $this->attributes[$key] = $value;
    }

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    public function setDynamic(bool $dynamic): void
    {
        $this->dynamic = $dynamic;
    }

    public function getLastInventoryAt(): ?\DateTimeImmutable
    {
        return $this->lastInventoryAt;
    }

    public function markInventoried(\DateTimeImmutable $at): void
    {
        $this->lastInventoryAt = $at;
        $this->dynamic = true;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function softDelete(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
    }

    public function restore(): void
    {
        $this->deletedAt = null;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }

    /** @return Collection<int, Component> */
    public function getComponents(): Collection
    {
        return $this->components;
    }

    public function addComponent(Component $component): void
    {
        if (!$this->components->contains($component)) {
            $this->components->add($component);
        }
    }

    public function removeComponent(Component $component): void
    {
        $this->components->removeElement($component);
    }

    /** @return Collection<int, SoftwareInstallation> */
    public function getSoftwareInstallations(): Collection
    {
        return $this->softwareInstallations;
    }

    public function addSoftwareInstallation(SoftwareInstallation $installation): void
    {
        if (!$this->softwareInstallations->contains($installation)) {
            $this->softwareInstallations->add($installation);
        }
    }

    public function removeSoftwareInstallation(SoftwareInstallation $installation): void
    {
        $this->softwareInstallations->removeElement($installation);
    }

    /** @return Collection<int, NetworkPort> */
    public function getNetworkPorts(): Collection
    {
        return $this->networkPorts;
    }

    public function addNetworkPort(NetworkPort $port): void
    {
        if (!$this->networkPorts->contains($port)) {
            $this->networkPorts->add($port);
        }
    }

    public function removeNetworkPort(NetworkPort $port): void
    {
        $this->networkPorts->removeElement($port);
    }
}
