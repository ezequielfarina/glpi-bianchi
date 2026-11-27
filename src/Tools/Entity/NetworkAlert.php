<?php

declare(strict_types=1);

namespace App\Tools\Entity;

use App\Asset\Entity\Asset;
use App\Organization\Entity\Branch;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Alerta de rede (ADR-0013): mudança detectada por varredura agendada ou conflito IP/MAC.
 * `fingerprint` (SHA-256 de tipo + chave) deduplica: enquanto o alerta estiver aberto
 * (`acknowledged_at` nulo), a repetição só incrementa `occurrences` e `last_seen_at`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'network_alert')]
#[ORM\Index(name: 'idx_network_alert_fingerprint', columns: ['fingerprint'])]
#[ORM\Index(name: 'idx_network_alert_seen', columns: ['last_seen_at'])]
class NetworkAlert
{
    public const array KINDS = ['new_device', 'device_gone', 'mac_changed', 'new_ports', 'ip_conflict', 'scan_failed', 'baseline'];
    public const array SEVERITIES = ['info', 'warning', 'critical'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?NetworkScan $scan = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ScanSchedule $schedule = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Branch $branch = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Asset $asset = null;

    #[ORM\Column(length: 32)]
    private string $kind;

    #[ORM\Column(length: 16)]
    private string $severity;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column(length: 255)]
    private string $title;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $detail = [];

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 17, nullable: true)]
    private ?string $mac = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $occurrences = 1;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $acknowledgedBy = null;

    public function __construct(string $kind, string $severity, string $fingerprint, string $title)
    {
        $this->kind = $kind;
        $this->severity = $severity;
        $this->fingerprint = $fingerprint;
        $this->title = $title;
        $this->createdAt = $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }
}
