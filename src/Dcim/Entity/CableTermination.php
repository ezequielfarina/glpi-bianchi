<?php

declare(strict_types=1);

namespace App\Dcim\Entity;

use App\Circuit\Entity\CircuitTermination;
use Doctrine\ORM\Mapping as ORM;

/**
 * Terminação de cabo (ponta A/B) em porta ou terminação de circuito. Porta de `dcim.CableTermination`, com FK real no lugar da GenericForeignKey.
 *
 * Regras de negócio e escrita: serviços em src/Dcim/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dcim_cable_termination')]
#[ORM\UniqueConstraint(name: 'uniq_dcim_cable_termination_port', columns: ['port_id'])]
#[ORM\UniqueConstraint(name: 'uniq_dcim_cable_termination_circuit', columns: ['circuit_termination_id'])]
class CableTermination
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Cable::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Cable $cable;

    #[ORM\ManyToOne(targetEntity: Port::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Port $port = null;

    #[ORM\ManyToOne(targetEntity: CircuitTermination::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?CircuitTermination $circuitTermination = null;

    #[ORM\Column(length: 1)]
    private string $cableEnd;

    public function __construct(Cable $cable, string $cableEnd)
    {
        $this->cable = $cable;
        $this->cableEnd = $cableEnd;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCable(): Cable
    {
        return $this->cable;
    }

    public function getPort(): ?Port
    {
        return $this->port;
    }

    public function getCircuitTermination(): ?CircuitTermination
    {
        return $this->circuitTermination;
    }

    public function getCableEnd(): string
    {
        return $this->cableEnd;
    }
}
