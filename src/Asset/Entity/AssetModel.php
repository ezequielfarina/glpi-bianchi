<?php

declare(strict_types=1);

namespace App\Asset\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Modelo comercial (ex.: "OptiPlex 7090", "FortiGate 100F").
 * `productNumber` = SKU/part number quando o agente informa.
 */
#[ORM\Entity]
#[ORM\Table(name: 'asset_model')]
#[ORM\UniqueConstraint(name: 'uniq_asset_model', columns: ['asset_type_id', 'manufacturer_id', 'name'])]
#[ApiResource(operations: [new Get(), new GetCollection()])]
class AssetModel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private AssetType $assetType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Manufacturer $manufacturer;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $productNumber = null;

    public function __construct(AssetType $assetType, string $name, ?Manufacturer $manufacturer = null)
    {
        $this->assetType = $assetType;
        $this->name = trim($name);
        $this->manufacturer = $manufacturer;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssetType(): AssetType
    {
        return $this->assetType;
    }

    public function getManufacturer(): ?Manufacturer
    {
        return $this->manufacturer;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProductNumber(): ?string
    {
        return $this->productNumber;
    }

    public function setProductNumber(?string $productNumber): void
    {
        $this->productNumber = $productNumber;
    }
}
