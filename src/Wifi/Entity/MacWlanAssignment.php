<?php

declare(strict_types=1);

namespace App\Wifi\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * MAC identificado em uma rede Wi-Fi e o estado da aplicação no controller.
 *
 * Regras e escrita: serviços em src/Wifi/Service (DBAL). Ver ADR-0020.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mac_wlan_assignment')]
#[ORM\UniqueConstraint(name: 'uniq_mac_wlan', columns: ['identity_id', 'wlan_id'])]
#[ORM\Index(name: 'idx_mac_wlan_state', columns: ['wlan_id', 'state'])]
#[ORM\HasLifecycleCallbacks]
class MacWlanAssignment
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MacIdentity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MacIdentity $identity;

    #[ORM\ManyToOne(targetEntity: UnifiWlan::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UnifiWlan $wlan;

    /** pending | synced | error | removing */
    #[ORM\Column(length: 12, options: ['default' => 'pending'])]
    private string $state = 'pending';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $createdBy = null;

    public function __construct(MacIdentity $identity, UnifiWlan $wlan)
    {
        $this->identity = $identity;
        $this->wlan = $wlan;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentity(): MacIdentity
    {
        return $this->identity;
    }

    public function getWlan(): UnifiWlan
    {
        return $this->wlan;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }
}
