<?php

declare(strict_types=1);

namespace App\Dcim\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapeamento porta frontal (posição) ↔ porta traseira (posição). Porta de `dcim.PortMapping` (NetBox ≥ 4.5).
 *
 * Regras de negócio e escrita: serviços em src/Dcim/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dcim_port_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_dcim_port_mapping_front', columns: ['front_port_id', 'front_position'])]
#[ORM\UniqueConstraint(name: 'uniq_dcim_port_mapping_rear', columns: ['rear_port_id', 'rear_position'])]
class PortMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Port::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Port $frontPort;

    #[ORM\ManyToOne(targetEntity: Port::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Port $rearPort;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $frontPosition = 1;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $rearPosition = 1;

    public function __construct(Port $frontPort, Port $rearPort)
    {
        $this->frontPort = $frontPort;
        $this->rearPort = $rearPort;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFrontPort(): Port
    {
        return $this->frontPort;
    }

    public function getRearPort(): Port
    {
        return $this->rearPort;
    }

    public function getFrontPosition(): int
    {
        return $this->frontPosition;
    }

    public function getRearPosition(): int
    {
        return $this->rearPosition;
    }
}
