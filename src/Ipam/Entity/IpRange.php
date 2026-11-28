<?php

declare(strict_types=1);

namespace App\Ipam\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Faixa de IPs (início–fim). Porta de `ipam.IPRange`.
 *
 * Regras de negócio e escrita: serviços em src/Ipam/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ipam_ip_range')]
#[ORM\Index(name: 'idx_ipam_ip_range_start', columns: ['start_address'])]
#[ORM\HasLifecycleCallbacks]
class IpRange
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'inet')]
    private string $startAddress;

    #[ORM\Column(type: 'inet')]
    private string $endAddress;

    #[ORM\Column(length: 16, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $markUtilized = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function __construct(string $startAddress, string $endAddress)
    {
        $this->startAddress = $startAddress;
        $this->endAddress = $endAddress;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartAddress(): string
    {
        return $this->startAddress;
    }

    public function getEndAddress(): string
    {
        return $this->endAddress;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function isMarkUtilized(): bool
    {
        return $this->markUtilized;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
