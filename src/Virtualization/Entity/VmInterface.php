<?php

declare(strict_types=1);

namespace App\Virtualization\Entity;

use App\Ipam\Entity\Vlan;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Interface de rede da VM (bridge/port group, VLAN). Porta de `virtualization.VMInterface`.
 *
 * Regras de negócio e escrita: serviços em src/Virtualization/Service (DBAL). Ver ADR-0017.
 */
#[ORM\Entity]
#[ORM\Table(name: 'virt_interface')]
#[ORM\UniqueConstraint(name: 'uniq_virt_interface_name', columns: ['vm_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class VmInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VirtualMachine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VirtualMachine $vm;

    #[ORM\ManyToOne(targetEntity: Vlan::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Vlan $untaggedVlan = null;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(length: 17, nullable: true)]
    private ?string $macAddress = null;

    #[ORM\Column(nullable: true)]
    private ?int $mtu = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $mode = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $bridge = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Vlan> VLANs tagged (modo 802.1Q) */
    #[ORM\ManyToMany(targetEntity: Vlan::class)]
    #[ORM\JoinTable(name: 'virt_interface_vlan')]
    #[ORM\JoinColumn(name: 'vm_interface_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'vlan_id', onDelete: 'CASCADE')]
    private Collection $taggedVlans;

    public function __construct(VirtualMachine $vm, string $name)
    {
        $this->vm = $vm;
        $this->name = $name;
        $this->taggedVlans = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVm(): VirtualMachine
    {
        return $this->vm;
    }

    public function getUntaggedVlan(): ?Vlan
    {
        return $this->untaggedVlan;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getMacAddress(): ?string
    {
        return $this->macAddress;
    }

    public function getMtu(): ?int
    {
        return $this->mtu;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function getBridge(): ?string
    {
        return $this->bridge;
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
