<?php

declare(strict_types=1);

namespace App\Ipam\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * VRF. Porta de `ipam.VRF` (netbox/ipam/models/vrfs.py).
 *
 * Regras de negócio e escrita: serviços em src/Ipam/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ipam_vrf')]
#[ORM\UniqueConstraint(name: 'uniq_ipam_vrf_name', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'uniq_ipam_vrf_rd', columns: ['rd'])]
#[ORM\HasLifecycleCallbacks]
class Vrf
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 21, nullable: true)]
    private ?string $rd = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enforceUnique = true;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function getRd(): ?string
    {
        return $this->rd;
    }

    public function isEnforceUnique(): bool
    {
        return $this->enforceUnique;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
