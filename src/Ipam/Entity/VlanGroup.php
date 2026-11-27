<?php

declare(strict_types=1);

namespace App\Ipam\Entity;

use App\Organization\Entity\Branch;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Grupo de VLANs com faixa de VID. Porta de `ipam.VLANGroup`.
 *
 * Regras de negócio e escrita: serviços em src/Ipam/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ipam_vlan_group')]
#[ORM\UniqueConstraint(name: 'uniq_ipam_vlan_group_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class VlanGroup
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Branch $branch = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $minVid = 1;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 4094])]
    private int $maxVid = 4094;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMinVid(): int
    {
        return $this->minVid;
    }

    public function getMaxVid(): int
    {
        return $this->maxVid;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
