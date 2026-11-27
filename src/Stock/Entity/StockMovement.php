<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use App\Asset\Entity\Asset;
use App\Organization\Entity\Branch;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Movimentação do estoque (ADR-0014) — razão imutável; o saldo é a soma de `quantity`.
 * - in: entrada (nota fiscal via `invoiceItem` ou entrada manual), quantidade > 0
 * - out: saída para filial (`branch`), quantidade < 0
 * - adjust: correção com motivo, qualquer sinal
 * Item com patrimônio: uma linha por unidade, com `asset`. `batch` agrupa a mesma operação.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_movement')]
#[ORM\Index(name: 'idx_stock_movement_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_stock_movement_batch', columns: ['batch'])]
class StockMovement
{
    public const array KINDS = ['in', 'out', 'adjust'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private StockItem $stockItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?InvoiceItem $invoiceItem = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Branch $branch = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Asset $asset = null;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $unitCost = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $batch = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $recipient = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 255)]
    private string $actor;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(StockItem $stockItem, string $kind, int $quantity, string $actor)
    {
        $this->stockItem = $stockItem;
        $this->kind = $kind;
        $this->quantity = $quantity;
        $this->actor = $actor;
        $this->occurredAt = $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getStockItem(): StockItem
    {
        return $this->stockItem;
    }

    public function getInvoiceItem(): ?InvoiceItem
    {
        return $this->invoiceItem;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function getAsset(): ?Asset
    {
        return $this->asset;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitCost(): ?string
    {
        return $this->unitCost;
    }

    public function getBatch(): ?Uuid
    {
        return $this->batch;
    }

    public function getRecipient(): ?string
    {
        return $this->recipient;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
