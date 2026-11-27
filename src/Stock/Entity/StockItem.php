<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use App\Asset\Entity\AssetType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Item do estoque central de TI (ADR-0014). `tracked` = cada unidade tem patrimônio: a entrada
 * cria um ativo `in_stock` por unidade (tipo `assetType`) e a saída o entrega à filial.
 * O saldo não é coluna: é a soma de `stock_movement.quantity`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_item')]
#[ORM\UniqueConstraint(name: 'uniq_stock_item_name', columns: ['name'])]
class StockItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?AssetType $assetType = null;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 64)]
    private string $category;

    #[ORM\Column(options: ['default' => false])]
    private bool $tracked = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $minQuantity = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $category)
    {
        $this->name = $name;
        $this->category = $category;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getAssetType(): ?AssetType
    {
        return $this->assetType;
    }

    public function isTracked(): bool
    {
        return $this->tracked;
    }

    public function getMinQuantity(): int
    {
        return $this->minQuantity;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
