<?php

declare(strict_types=1);

namespace App\Circuit\Entity;

use App\Contract\Entity\Contract;
use App\Shared\Entity\TimestampableTrait;
use App\Supplier\Entity\Supplier;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Circuito de operadora (operadora = fornecedor). Porta de `circuits.Circuit` (netbox/circuits/models/circuits.py).
 *
 * Regras de negócio e escrita: serviços em src/Circuit/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'circuit')]
#[ORM\UniqueConstraint(name: 'uniq_circuit_cid', columns: ['supplier_id', 'cid'])]
#[ORM\HasLifecycleCallbacks]
class Circuit
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Supplier $supplier;

    #[ORM\ManyToOne(targetEntity: CircuitType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private CircuitType $type;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Contract $contract = null;

    #[ORM\Column(length: 100)]
    private string $cid;

    #[ORM\Column(length: 16, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(nullable: true)]
    private ?int $commitRateKbps = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $installDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $terminationDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    public function __construct(Supplier $supplier, CircuitType $type, string $cid)
    {
        $this->supplier = $supplier;
        $this->type = $type;
        $this->cid = $cid;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getType(): CircuitType
    {
        return $this->type;
    }

    public function getContract(): ?Contract
    {
        return $this->contract;
    }

    public function getCid(): string
    {
        return $this->cid;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCommitRateKbps(): ?int
    {
        return $this->commitRateKbps;
    }

    public function getInstallDate(): ?\DateTimeImmutable
    {
        return $this->installDate;
    }

    public function getTerminationDate(): ?\DateTimeImmutable
    {
        return $this->terminationDate;
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
