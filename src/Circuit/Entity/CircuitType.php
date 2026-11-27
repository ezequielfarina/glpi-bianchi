<?php

declare(strict_types=1);

namespace App\Circuit\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tipo de circuito (Internet dedicada, MPLS…). Porta de `circuits.CircuitType`.
 *
 * Regras de negócio e escrita: serviços em src/Circuit/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'circuit_type')]
#[ORM\UniqueConstraint(name: 'uniq_circuit_type_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class CircuitType
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
