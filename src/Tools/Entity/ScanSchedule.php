<?php

declare(strict_types=1);

namespace App\Tools\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Varredura agendada (ADR-0013). `frequency`: `interval` (a cada N horas) ou `daily`
 * (horário HH:MM nos dias ISO de `weekdays`, 1 = segunda). O `bianchi-scan-scheduler.timer`
 * dispara as vencidas (`next_run_at`). A comunidade SNMP fica **cifrada** (SecretBox).
 * `alerts`: quais mudanças geram alerta (ver ScheduleService::ALERT_DEFAULTS).
 */
#[ORM\Entity]
#[ORM\Table(name: 'scan_schedule')]
class ScanSchedule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 43)]
    private string $target;

    #[ORM\Column(length: 16)]
    private string $profile;

    #[ORM\Column(length: 16)]
    private string $frequency;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $intervalHours = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $runAt = null;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[1, 2, 3, 4, 5, 6, 7]'])]
    private array $weekdays = [1, 2, 3, 4, 5, 6, 7];

    #[ORM\Column(length: 15, nullable: true)]
    private ?string $snmpGateway = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $snmpCommunity = null;

    /** @var array<string, bool> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $alerts = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(length: 64)]
    private string $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $target, string $profile, string $frequency, string $createdBy)
    {
        $this->name = $name;
        $this->target = $target;
        $this->profile = $profile;
        $this->frequency = $frequency;
        $this->createdBy = $createdBy;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
