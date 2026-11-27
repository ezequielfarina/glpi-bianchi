<?php

declare(strict_types=1);

namespace App\Ipam\Entity;

use App\Organization\Entity\Branch;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * VLAN documentada (IPAM) — diferente de `network_port_vlan`, que vem do inventário do agente. Porta de `ipam.VLAN`.
 *
 * Regras de negócio e escrita: serviços em src/Ipam/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ipam_vlan')]
#[ORM\UniqueConstraint(name: 'uniq_ipam_vlan_group_vid', columns: ['group_id', 'vid'])]
#[ORM\HasLifecycleCallbacks]
class Vlan
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VlanGroup::class)]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?VlanGroup $group = null;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Branch $branch = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $vid;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(length: 16, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function __construct(int $vid, string $name)
    {
        $this->vid = $vid;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroup(): ?VlanGroup
    {
        return $this->group;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function getVid(): int
    {
        return $this->vid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
