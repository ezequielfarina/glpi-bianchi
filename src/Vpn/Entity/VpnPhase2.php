<?php

declare(strict_types=1);

namespace App\Vpn\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Fase 2 (seletores e criptografia ESP) de um túnel IPsec — ADR-0022. */
#[ORM\Entity]
#[ORM\Table(name: 'vpn_phase2')]
#[ORM\UniqueConstraint(name: 'uniq_vpn_phase2', columns: ['tunnel_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class VpnPhase2
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VpnTunnel::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VpnTunnel $tunnel;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $proposal = null;

    #[ORM\Column(nullable: true)]
    private ?bool $pfs = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $dhgroups = null;

    #[ORM\Column(nullable: true)]
    private ?int $keylifeSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?bool $autoNegotiate = null;

    /** @var list<string> redes locais (CIDR) */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $src = [];

    /** @var list<string> redes remotas (CIDR) */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $dst = [];

    /** objeto de endereço usado no FortiGate (src-name), se houver */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $srcName = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $dstName = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $params = [];

    public function __construct(VpnTunnel $tunnel, string $name)
    {
        $this->tunnel = $tunnel;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTunnel(): VpnTunnel
    {
        return $this->tunnel;
    }

    /** @return list<string> */
    public function getSrc(): array
    {
        return $this->src;
    }

    /** @return list<string> */
    public function getDst(): array
    {
        return $this->dst;
    }

    /** @return array<string, string> */
    public function getParams(): array
    {
        return $this->params;
    }
}
