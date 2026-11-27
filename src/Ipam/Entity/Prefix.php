<?php

declare(strict_types=1);

namespace App\Ipam\Entity;

use App\Organization\Entity\Branch;
use App\Organization\Entity\Location;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Prefixo IP; hierarquia por contenção (<<), sem parent_id. Porta de `ipam.Prefix` (netbox/ipam/models/ip.py).
 *
 * Regras de negócio e escrita: serviços em src/Ipam/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ipam_prefix')]
#[ORM\HasLifecycleCallbacks]
class Prefix
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vrf::class)]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Vrf $vrf = null;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Branch $branch = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $location = null;

    #[ORM\ManyToOne(targetEntity: Vlan::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Vlan $vlan = null;

    #[ORM\Column(type: 'cidr')]
    private string $prefix;

    #[ORM\Column(length: 16, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPool = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $markUtilized = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVrf(): ?Vrf
    {
        return $this->vrf;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function getVlan(): ?Vlan
    {
        return $this->vlan;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function isPool(): bool
    {
        return $this->isPool;
    }

    public function isMarkUtilized(): bool
    {
        return $this->markUtilized;
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
