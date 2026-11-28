<?php

declare(strict_types=1);

namespace App\Virtualization\Entity;

use App\Organization\Entity\Branch;
use App\Organization\Entity\Location;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cluster de virtualização (um Proxmox, um vCenter/ESXi). Os hosts físicos são dcim_device.cluster_id. Porta de `virtualization.Cluster` (netbox/virtualization/models/clusters.py).
 *
 * Regras de negócio e escrita: serviços em src/Virtualization/Service (DBAL). Ver ADR-0017.
 */
#[ORM\Entity]
#[ORM\Table(name: 'virt_cluster')]
#[ORM\UniqueConstraint(name: 'uniq_virt_cluster_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class Cluster
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ClusterType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ClusterType $type;

    #[ORM\ManyToOne(targetEntity: ClusterGroup::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ClusterGroup $group = null;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Branch $branch = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $location = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 16, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $version = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $managementUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    public function __construct(ClusterType $type, string $name)
    {
        $this->type = $type;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ClusterType
    {
        return $this->type;
    }

    public function getGroup(): ?ClusterGroup
    {
        return $this->group;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function getManagementUrl(): ?string
    {
        return $this->managementUrl;
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
