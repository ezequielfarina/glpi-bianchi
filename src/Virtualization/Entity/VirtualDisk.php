<?php

declare(strict_types=1);

namespace App\Virtualization\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Disco virtual da VM. Porta de `virtualization.VirtualDisk`.
 *
 * Regras de negócio e escrita: serviços em src/Virtualization/Service (DBAL). Ver ADR-0017.
 */
#[ORM\Entity]
#[ORM\Table(name: 'virt_disk')]
#[ORM\UniqueConstraint(name: 'uniq_virt_disk_name', columns: ['vm_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class VirtualDisk
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VirtualMachine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VirtualMachine $vm;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column]
    private int $sizeGb;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $storage = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function __construct(VirtualMachine $vm, string $name, int $sizeGb)
    {
        $this->vm = $vm;
        $this->name = $name;
        $this->sizeGb = $sizeGb;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVm(): VirtualMachine
    {
        return $this->vm;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSizeGb(): int
    {
        return $this->sizeGb;
    }

    public function getStorage(): ?string
    {
        return $this->storage;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
