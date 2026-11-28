<?php

declare(strict_types=1);

namespace App\Wifi\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rede Wi-Fi (WLAN/SSID) de um site, com o estado do filtro de MAC lido do controller.
 *
 * Regras e escrita: serviços em src/Wifi/Service (DBAL). Ver ADR-0020.
 */
#[ORM\Entity]
#[ORM\Table(name: 'unifi_wlan')]
#[ORM\UniqueConstraint(name: 'uniq_unifi_wlan_ext', columns: ['site_id', 'external_id'])]
#[ORM\HasLifecycleCallbacks]
class UnifiWlan
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UnifiSite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UnifiSite $site;

    /** _id da WLAN no controller (ex.: 632b7017c6d7130320e89bb6) */
    #[ORM\Column(length: 64)]
    private string $externalId;

    /** SSID */
    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $security = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $filterEnabled = false;

    /** allow (só a lista conecta) | deny (a lista é bloqueada) */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $filterPolicy = null;

    /** @var list<string> lista de MACs no controller na última leitura */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $remoteMacs = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $present = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    public function __construct(UnifiSite $site, string $externalId, string $name)
    {
        $this->site = $site;
        $this->externalId = $externalId;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): UnifiSite
    {
        return $this->site;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSecurity(): ?string
    {
        return $this->security;
    }

    public function isFilterEnabled(): bool
    {
        return $this->filterEnabled;
    }

    public function getFilterPolicy(): ?string
    {
        return $this->filterPolicy;
    }

    /** @return list<string> */
    public function getRemoteMacs(): array
    {
        return $this->remoteMacs;
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }
}
