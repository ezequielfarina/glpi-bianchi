<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Linha da nota fiscal: item do estoque, quantidade e valor unitário (ADR-0014). */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_item')]
class InvoiceItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private StockItem $stockItem;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position = 0;

    public function __construct(Invoice $invoice, StockItem $stockItem, int $quantity, string $unitPrice)
    {
        $this->invoice = $invoice;
        $this->stockItem = $stockItem;
        $this->quantity = $quantity;
        $this->unitPrice = $unitPrice;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getStockItem(): StockItem
    {
        return $this->stockItem;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
