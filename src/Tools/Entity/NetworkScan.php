<?php

declare(strict_types=1);

namespace App\Tools\Entity;

use App\Organization\Entity\Branch;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Execução do scanner de rede (ADR-0012). Status: queued → running → done | failed | cancelled.
 * `options` guarda o perfil efetivo e se houve consulta SNMP — **nunca** a comunidade SNMP.
 */
#[ORM\Entity]
#[ORM\Table(name: 'network_scan')]
#[ORM\Index(name: 'idx_network_scan_created', columns: ['created_at'])]
class NetworkScan
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';
    public const string CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** CIDR normalizado (ex.: 192.168.10.0/24) */
    #[ORM\Column(length: 43)]
    private string $target;

    #[ORM\Column(length: 16)]
    private string $profile;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $options = [];

    #[ORM\Column(length: 16, options: ['default' => self::QUEUED])]
    private string $status = self::QUEUED;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $phase = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $progress = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $hostsUp = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Branch $branch = null;

    /** Agendamento que disparou (null = manual) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ScanSchedule $schedule = null;

    #[ORM\Column(length: 64)]
    private string $requestedBy;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(string $target, string $profile, string $requestedBy)
    {
        $this->target = $target;
        $this->profile = $profile;
        $this->requestedBy = $requestedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTarget(): string { return $this->target; }
    public function getProfile(): string { return $this->profile; }
    public function getStatus(): string { return $this->status; }
    public function getBranch(): ?Branch { return $this->branch; }
}
