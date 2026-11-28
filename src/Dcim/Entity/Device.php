<?php

declare(strict_types=1);

namespace App\Dcim\Entity;

use App\Asset\Entity\Asset;
use App\Ipam\Entity\IpAddress;
use App\Organization\Entity\Branch;
use App\Organization\Entity\Location;
use App\Shared\Entity\TimestampableTrait;
use App\Virtualization\Entity\Cluster;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Equipamento físico posicionado em filial/local/rack; vínculo opcional com o ativo do inventário. Porta de `dcim.Device`.
 *
 * Regras de negócio e escrita: serviços em src/Dcim/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dcim_device')]
#[ORM\UniqueConstraint(name: 'uniq_dcim_device_name', columns: ['branch_id', 'name'])]
#[ORM\UniqueConstraint(name: 'uniq_dcim_device_asset', columns: ['asset_id'])]
#[ORM\UniqueConstraint(name: 'uniq_dcim_device_primary_ip', columns: ['primary_ip_id'])]
#[ORM\HasLifecycleCallbacks]
class Device
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Branch $branch;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $location = null;

    #[ORM\ManyToOne(targetEntity: Rack::class)]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Rack $rack = null;

    #[ORM\ManyToOne(targetEntity: DeviceType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DeviceType $deviceType;

    #[ORM\ManyToOne(targetEntity: DeviceRole::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DeviceRole $role;

    #[ORM\ManyToOne(targetEntity: Asset::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Asset $asset = null;

    #[ORM\ManyToOne(targetEntity: IpAddress::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?IpAddress $primaryIp = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 16, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    private ?string $position = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $face = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $serial = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $assetTag = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    #[ORM\ManyToOne(targetEntity: Cluster::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Cluster $cluster = null;

    #[ORM\Column(nullable: true)]
    private ?int $cpuThreads = null;

    #[ORM\Column(nullable: true)]
    private ?int $memoryMb = null;

    #[ORM\Column(nullable: true)]
    private ?int $storageGb = null;

    public function __construct(Branch $branch, DeviceType $deviceType, DeviceRole $role, string $name)
    {
        $this->branch = $branch;
        $this->deviceType = $deviceType;
        $this->role = $role;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBranch(): Branch
    {
        return $this->branch;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function getRack(): ?Rack
    {
        return $this->rack;
    }

    public function getDeviceType(): DeviceType
    {
        return $this->deviceType;
    }

    public function getRole(): DeviceRole
    {
        return $this->role;
    }

    public function getAsset(): ?Asset
    {
        return $this->asset;
    }

    public function getPrimaryIp(): ?IpAddress
    {
        return $this->primaryIp;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function getFace(): ?string
    {
        return $this->face;
    }

    public function getSerial(): ?string
    {
        return $this->serial;
    }

    public function getAssetTag(): ?string
    {
        return $this->assetTag;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getComments(): ?string
    {
        return $this->comments;
    }

    public function getCluster(): ?Cluster
    {
        return $this->cluster;
    }

    public function getCpuThreads(): ?int
    {
        return $this->cpuThreads;
    }

    public function getMemoryMb(): ?int
    {
        return $this->memoryMb;
    }

    public function getStorageGb(): ?int
    {
        return $this->storageGb;
    }
}
