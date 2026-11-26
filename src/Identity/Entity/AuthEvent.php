<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trilha de auditoria de acesso (append-only) — também é a base do bloqueio por
 * tentativas (LoginThrottle conta `login_failed` por usuário e por IP).
 *
 * `username` guarda o login digitado (mesmo inexistente), sem a senha, nunca.
 * `actor` = administrador que executou ações sobre a conta (criar, resetar...).
 */
#[ORM\Entity]
#[ORM\Table(name: 'auth_event')]
#[ORM\Index(name: 'idx_auth_event_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_auth_event_username_created', columns: ['username', 'created_at'])]
#[ORM\Index(name: 'idx_auth_event_ip_created', columns: ['ip', 'created_at'])]
class AuthEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Account $account = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 32)]
    private string $event;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $actor = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $details = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $event)
    {
        $this->event = $event;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }
    public function getEvent(): string { return $this->event; }
    public function getAccount(): ?Account { return $this->account; }
    public function getUsername(): ?string { return $this->username; }
    public function getIp(): ?string { return $this->ip; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getActor(): ?string { return $this->actor; }
    /** @return array<string, mixed> */
    public function getDetails(): array { return $this->details; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
