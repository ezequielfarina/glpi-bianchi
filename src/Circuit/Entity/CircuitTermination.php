<?php

declare(strict_types=1);

namespace App\Circuit\Entity;

use App\Organization\Entity\Branch;
use App\Organization\Entity\Location;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Terminação A/Z do circuito. Porta de `circuits.CircuitTermination`.
 *
 * Regras de negócio e escrita: serviços em src/Circuit/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'circuit_termination')]
#[ORM\UniqueConstraint(name: 'uniq_circuit_termination_side', columns: ['circuit_id', 'term_side'])]
#[ORM\HasLifecycleCallbacks]
class CircuitTermination
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Circuit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Circuit $circuit;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Branch $branch = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $location = null;

    #[ORM\Column(length: 1)]
    private string $termSide;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $providerNetwork = null;

    #[ORM\Column(nullable: true)]
    private ?int $portSpeedKbps = null;

    #[ORM\Column(nullable: true)]
    private ?int $upstreamSpeedKbps = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $xconnectId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ppInfo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function __construct(Circuit $circuit, string $termSide)
    {
        $this->circuit = $circuit;
        $this->termSide = $termSide;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCircuit(): Circuit
    {
        return $this->circuit;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function getTermSide(): string
    {
        return $this->termSide;
    }

    public function getProviderNetwork(): ?string
    {
        return $this->providerNetwork;
    }

    public function getPortSpeedKbps(): ?int
    {
        return $this->portSpeedKbps;
    }

    public function getUpstreamSpeedKbps(): ?int
    {
        return $this->upstreamSpeedKbps;
    }

    public function getXconnectId(): ?string
    {
        return $this->xconnectId;
    }

    public function getPpInfo(): ?string
    {
        return $this->ppInfo;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
