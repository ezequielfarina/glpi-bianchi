<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Identity\Entity\Account;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Usuário autenticado na requisição (montado a partir da sessão, sem ORM).
 * Perfis → papéis Symfony: admin → ROLE_ADMIN ⊃ ROLE_EDITOR ⊃ ROLE_VIEWER (role_hierarchy).
 */
final readonly class AuthenticatedAccount implements UserInterface
{
    public function __construct(
        public int $id,
        public string $username,
        public string $displayName,
        public string $role,
        public bool $mustChangePassword,
        public bool $hasTotp,
        public string $sessionId,
    ) {
    }

    /** @param array<string, mixed> $row linha de app_account (+ session_id) */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['account_id'],
            (string) $row['username'],
            (string) $row['display_name'],
            (string) $row['role'],
            (bool) $row['must_change_password'],
            null !== $row['totp_enabled_at'],
            (string) $row['session_id'],
        );
    }

    public function getRoles(): array
    {
        return [match ($this->role) {
            Account::ROLE_ADMIN => 'ROLE_ADMIN',
            Account::ROLE_EDITOR => 'ROLE_EDITOR',
            default => 'ROLE_VIEWER',
        }];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return '' !== $this->username ? $this->username : 'desconhecido';
    }
}
