<?php

declare(strict_types=1);

namespace App\Dcim\Entity;

use App\Asset\Entity\Manufacturer;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tipo de equipamento (fabricante + modelo, altura em U). Porta de `dcim.DeviceType` (netbox/dcim/models/devices.py).
 *
 * Regras de negócio e escrita: serviços em src/Dcim/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dcim_device_type')]
#[ORM\UniqueConstraint(name: 'uniq_dcim_device_type_model', columns: ['manufacturer_id', 'model'])]
#[ORM\HasLifecycleCallbacks]
class DeviceType
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Manufacturer $manufacturer;

    #[ORM\ManyToOne(targetEntity: DeviceRole::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?DeviceRole $defaultRole = null;

    #[ORM\Column(length: 150)]
    private string $model;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $partNumber = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 1, options: ['default' => '1'])]
    private string $uHeight = '1';

    #[ORM\Column(options: ['default' => true])]
    private bool $isFullDepth = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    public function __construct(Manufacturer $manufacturer, string $model)
    {
        $this->manufacturer = $manufacturer;
        $this->model = $model;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getManufacturer(): Manufacturer
    {
        return $this->manufacturer;
    }

    public function getDefaultRole(): ?DeviceRole
    {
        return $this->defaultRole;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getPartNumber(): ?string
    {
        return $this->partNumber;
    }

    public function getUHeight(): string
    {
        return $this->uHeight;
    }

    public function isFullDepth(): bool
    {
        return $this->isFullDepth;
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
