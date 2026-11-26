<?php

declare(strict_types=1);

namespace App\Identity\Security;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Registro de eventos de acesso em `auth_event` (consultável no painel) e no
 * log `security` (JSON em produção → syslog/SIEM). Nunca registra senha nem código.
 */
final readonly class AuthAudit
{
    public const array EVENTS = [
        'login_ok', 'password_ok', 'login_failed', 'login_throttled', 'mfa_failed', 'logout',
        'password_changed', 'password_reset', 'mfa_enabled', 'mfa_disabled', 'mfa_reset',
        'account_created', 'account_updated', 'account_disabled', 'account_enabled', 'account_deleted',
        'unlock', 'sessions_revoked',
    ];

    public function __construct(
        private Connection $db,
        #[Autowire(service: 'monolog.logger.security')]
        private LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $details */
    public function log(string $event, ?int $accountId, ?string $username, ?Request $request = null, ?string $actor = null, array $details = []): void
    {
        $ip = $request?->getClientIp();
        $username = null === $username ? null : mb_substr(mb_strtolower(trim($username)), 0, 64);
        $this->db->executeStatement(
            'INSERT INTO auth_event (account_id, username, event, ip, user_agent, actor, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, now())',
            [$accountId, $username, $event, $ip, null === $request ? null : mb_substr((string) $request->headers->get('User-Agent'), 0, 512), $actor,
                json_encode((object) $details, \JSON_UNESCAPED_UNICODE)],
        );
        $level = \in_array($event, ['login_failed', 'login_throttled', 'mfa_failed'], true) ? 'warning' : 'info';
        $this->logger->log($level, 'auth.'.$event, ['username' => $username, 'ip' => $ip, 'actor' => $actor] + $details);
    }

    /** Retenção: apaga eventos com mais de 365 dias (chamado no login, em lotes). */
    public function purge(): void
    {
        $this->db->executeStatement("DELETE FROM auth_event WHERE id IN (SELECT id FROM auth_event WHERE created_at < now() - interval '365 days' LIMIT 5000)");
    }
}
