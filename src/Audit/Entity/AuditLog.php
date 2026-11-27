<?php

declare(strict_types=1);

namespace App\Audit\Entity;

use App\Identity\Entity\Account;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trilha de auditoria (ADR-0015): uma linha por ação bem-sucedida feita pela interface.
 * Somente inclusão — o trigger `trg_audit_log_guard` bloqueia UPDATE/DELETE no banco.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_log_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_entity', columns: ['entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_log_actor', columns: ['actor', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_action', columns: ['action', 'occurred_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Account $account = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 255)]
    private string $actor;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column(length: 32)]
    private string $module;

    #[ORM\Column(length: 8)]
    private string $method;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $entityId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $status;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $details = [];

    public function __construct(string $actor, string $action, string $module, string $method, string $path, int $status)
    {
        $this->actor = $actor;
        $this->action = $action;
        $this->module = $module;
        $this->method = $method;
        $this->path = $path;
        $this->status = $status;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
