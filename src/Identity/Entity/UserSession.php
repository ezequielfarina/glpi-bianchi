<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sessão do painel (ADR-0011). O cookie leva um token aleatório de 256 bits;
 * o banco guarda só o SHA-256 dele — vazamento do banco não dá sessões válidas.
 *
 * `state`: `active` ou `mfa_pending` (senha ok, aguardando o código TOTP; vida curta).
 * Expira por inatividade (`lastSeenAt`) e por idade (`expiresAt`); `revokedAt` = logout,
 * troca/reset de senha, desativação da conta ou encerramento pelo administrador.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_session')]
#[ORM\UniqueConstraint(name: 'uniq_user_session_token', columns: ['token_hash'])]
class UserSession
{
    public const string STATE_ACTIVE = 'active';
    public const string STATE_MFA_PENDING = 'mfa_pending';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 16)]
    private string $state;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $mfaFailures = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(Account $account, string $tokenHash, string $state, \DateTimeImmutable $expiresAt)
    {
        $this->account = $account;
        $this->tokenHash = $tokenHash;
        $this->state = $state;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?string { return $this->id; }
    public function getAccount(): Account { return $this->account; }
    public function getState(): string { return $this->state; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
}
