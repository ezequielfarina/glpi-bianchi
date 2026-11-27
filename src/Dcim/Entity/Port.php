<?php

declare(strict_types=1);

namespace App\Dcim\Entity;

use App\Ipam\Entity\Vlan;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Porta de equipamento — uma tabela para os 7 tipos de componente do NetBox (interface, frontal, traseira, console, servidor de console, entrada e tomada de energia). Ver ADR-0016 §3.
 *
 * Regras de negócio e escrita: serviços em src/Dcim/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dcim_port')]
#[ORM\UniqueConstraint(name: 'uniq_dcim_port_name', columns: ['device_id', 'kind', 'name'])]
#[ORM\HasLifecycleCallbacks]
class Port
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\ManyToOne(targetEntity: Vlan::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Vlan $untaggedVlan = null;

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

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $mgmtOnly = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $markConnected = false;

    #[ORM\Column(length: 17, nullable: true)]
    private ?string $macAddress = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $mode = null;

    #[ORM\Column(nullable: true)]
    private ?int $maximumDraw = null;

    #[ORM\Column(nullable: true)]
    private ?int $allocatedDraw = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Vlan> VLANs tagged (modo 802.1Q) */
    #[ORM\ManyToMany(targetEntity: Vlan::class)]
    #[ORM\JoinTable(name: 'dcim_port_vlan')]
    #[ORM\JoinColumn(name: 'port_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'vlan_id', onDelete: 'CASCADE')]
    private Collection $taggedVlans;

    public function __construct(Device $device, string $kind, string $name, string $type)
    {
        $this->device = $device;
        $this->kind = $kind;
        $this->name = $name;
        $this->type = $type;
        $this->taggedVlans = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getUntaggedVlan(): ?Vlan
    {
        return $this->untaggedVlan;
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isMgmtOnly(): bool
    {
        return $this->mgmtOnly;
    }

    public function isMarkConnected(): bool
    {
        return $this->markConnected;
    }

    public function getMacAddress(): ?string
    {
        return $this->macAddress;
    }

    public function getMode(): ?string
    {
        return $this->mode;
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

    /** @return Collection<int, Vlan> */
    public function getTaggedVlans(): Collection
    {
        return $this->taggedVlans;
    }
}
