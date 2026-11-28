<?php

declare(strict_types=1);

namespace App\Virtualization\Entity;

use App\Asset\Entity\Asset;
use App\Dcim\Entity\Device;
use App\Dcim\Entity\DeviceRole;
use App\Ipam\Entity\IpAddress;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Máquina virtual com recursos (vCPU, RAM, disco), cluster e host. Porta de `virtualization.VirtualMachine` (netbox/virtualization/models/virtualmachines.py).
 *
 * Regras de negócio e escrita: serviços em src/Virtualization/Service (DBAL). Ver ADR-0017.
 */
#[ORM\Entity]
#[ORM\Table(name: 'virt_machine')]
#[ORM\UniqueConstraint(name: 'uniq_virt_machine_name', columns: ['cluster_id', 'name'])]
#[ORM\UniqueConstraint(name: 'uniq_virt_machine_asset', columns: ['asset_id'])]
#[ORM\UniqueConstraint(name: 'uniq_virt_machine_primary_ip', columns: ['primary_ip_id'])]
#[ORM\HasLifecycleCallbacks]
class VirtualMachine
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Cluster::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Cluster $cluster;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Device $device = null;

    #[ORM\ManyToOne(targetEntity: DeviceRole::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?DeviceRole $role = null;

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

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $platform = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $vmid = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    private ?string $vcpus = null;

    #[ORM\Column(nullable: true)]
    private ?int $memoryMb = null;

    #[ORM\Column(nullable: true)]
    private ?int $diskGb = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    public function __construct(Cluster $cluster, string $name)
    {
        $this->cluster = $cluster;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCluster(): Cluster
    {
        return $this->cluster;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function getRole(): ?DeviceRole
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

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    public function getVmid(): ?string
    {
        return $this->vmid;
    }

    public function getVcpus(): ?string
    {
        return $this->vcpus;
    }

    public function getMemoryMb(): ?int
    {
        return $this->memoryMb;
    }

    public function getDiskGb(): ?int
    {
        return $this->diskGb;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getComments(): ?string
    {
        return $this->comments;
    }
}
