<?php

declare(strict_types=1);

namespace App\Ipam\Entity;

use App\Dcim\Entity\Port;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Endereço IP (com máscara) atribuído opcionalmente a uma porta. Porta de `ipam.IPAddress`.
 *
 * Regras de negócio e escrita: serviços em src/Ipam/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ipam_ip_address')]
#[ORM\HasLifecycleCallbacks]
class IpAddress
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vrf::class)]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Vrf $vrf = null;

    #[ORM\ManyToOne(targetEntity: Port::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Port $port = null;

    #[ORM\Column(type: 'inet')]
    private string $address;

    #[ORM\Column(length: 16, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dnsName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    public function __construct(string $address)
    {
        $this->address = $address;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVrf(): ?Vrf
    {
        return $this->vrf;
    }

    public function getPort(): ?Port
    {
        return $this->port;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function getDnsName(): ?string
    {
        return $this->dnsName;
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
