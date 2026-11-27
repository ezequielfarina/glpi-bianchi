<?php

declare(strict_types=1);

namespace App\Dcim\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Modelo de porta do tipo de equipamento, instanciado ao criar o equipamento. Porta dos *component templates* (netbox/dcim/models/device_component_templates.py).
 *
 * Regras de negócio e escrita: serviços em src/Dcim/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dcim_port_template')]
#[ORM\UniqueConstraint(name: 'uniq_dcim_port_template_name', columns: ['device_type_id', 'kind', 'name'])]
class PortTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DeviceType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DeviceType $deviceType;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?self $rearPortTemplate = null;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $positions = 1;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $rearPosition = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $mgmtOnly = false;

    #[ORM\Column(nullable: true)]
    private ?int $maximumDraw = null;

    #[ORM\Column(nullable: true)]
    private ?int $allocatedDraw = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function __construct(DeviceType $deviceType, string $kind, string $name, string $type)
    {
        $this->deviceType = $deviceType;
        $this->kind = $kind;
        $this->name = $name;
        $this->type = $type;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeviceType(): DeviceType
    {
        return $this->deviceType;
    }

    public function getRearPortTemplate(): ?self
    {
        return $this->rearPortTemplate;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPositions(): int
    {
        return $this->positions;
    }

    public function getRearPosition(): ?int
    {
        return $this->rearPosition;
    }

    public function isMgmtOnly(): bool
    {
        return $this->mgmtOnly;
    }

    public function getMaximumDraw(): ?int
    {
        return $this->maximumDraw;
    }

    public function getAllocatedDraw(): ?int
    {
        return $this->allocatedDraw;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
