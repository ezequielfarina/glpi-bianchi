<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Conta de acesso ao painel (operador do sistema) — ADR-0011.
 *
 * Separada de `app_user` (pessoas a quem ativos são atribuídos, criadas também
 * pelo inventário): ninguém ganha acesso por aparecer como "último usuário logado".
 *
 * - `passwordHash`: Argon2id (libsodium, `$argon2id$…`), rehash automático no login;
 * - `totpSecret`: segredo TOTP cifrado (SecretBox/XSalsa20-Poly1305); 2FA ativo
 *   só com `totpEnabledAt`; `totpLastStep` impede reutilizar o mesmo código.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_account')]
#[ORM\UniqueConstraint(name: 'uniq_app_account_username', columns: ['username'])]
#[ORM\HasLifecycleCallbacks]
class Account
{
    use TimestampableTrait;

    public const string ROLE_ADMIN = 'admin';
    public const string ROLE_EDITOR = 'editor';
    public const string ROLE_VIEWER = 'viewer';
    public const array ROLES = [self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_VIEWER];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** login, minúsculo: [a-z0-9._-]{3,64} */
    #[ORM\Column(length: 64)]
    private string $username;

    #[ORM\Column(length: 255)]
    private string $displayName;

    #[ORM\Column(length: 320, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $mustChangePassword = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $totpEnabledAt = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $totpLastStep = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $lastLoginIp = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $passwordChangedAt = null;

    public function __construct(string $username, string $displayName, string $passwordHash, string $role)
    {
        $this->username = $username;
        $this->displayName = $displayName;
        $this->passwordHash = $passwordHash;
        $this->role = $role;
    }

    public function getId(): ?int { return $this->id; }
    public function getUsername(): string { return $this->username; }
    public function getDisplayName(): string { return $this->displayName; }
    public function getEmail(): ?string { return $this->email; }
    public function getRole(): string { return $this->role; }
    public function isActive(): bool { return $this->active; }
    public function mustChangePassword(): bool { return $this->mustChangePassword; }
    public function hasTotp(): bool { return null !== $this->totpEnabledAt; }
    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function getPasswordChangedAt(): ?\DateTimeImmutable { return $this->passwordChangedAt; }
}
