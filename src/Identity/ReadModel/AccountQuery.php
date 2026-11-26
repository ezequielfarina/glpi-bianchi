<?php

declare(strict_types=1);

namespace App\Identity\ReadModel;

use App\Identity\Security\LoginThrottle;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Leituras do painel de usuários, sessões e auditoria (nunca expõe hash nem segredo TOTP). */
final readonly class AccountQuery
{
    public function __construct(
        private Connection $db,
        private LoginThrottle $throttle,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT a.id, a.username, a.display_name, a.email, a.role, a.active, a.must_change_password,
                   a.totp_enabled_at IS NOT NULL AS mfa, a.last_login_at, a.last_login_ip, a.password_changed_at, a.created_at,
                   (SELECT count(*) FROM user_session s WHERE s.account_id = a.id AND s.state = 'active'
                      AND s.revoked_at IS NULL AND s.expires_at > now()) AS sessions
            FROM app_account a
            ORDER BY a.active DESC, a.display_name
            SQL);

        return array_map(fn (array $r): array => $this->cast($r), $rows);
    }

    /** @return array<string, mixed>|null */
    public function me(int $id, string $currentSessionId): ?array
    {
        $r = $this->db->fetchAssociative(
            'SELECT id, username, display_name, email, role, must_change_password, totp_enabled_at IS NOT NULL AS mfa, last_login_at, password_changed_at FROM app_account WHERE id = ?',
            [$id],
        );
        if (false === $r) {
            return null;
        }
        $previous = $this->db->fetchAssociative(
            "SELECT created_at, ip FROM auth_event WHERE account_id = ? AND event = 'login_ok' ORDER BY id DESC OFFSET 1 LIMIT 1",
            [$id],
        );

        return [
            'id' => (int) $r['id'],
            'username' => $r['username'],
            'display_name' => $r['display_name'],
            'email' => $r['email'],
            'role' => $r['role'],
            'must_change_password' => (bool) $r['must_change_password'],
            'mfa' => (bool) $r['mfa'],
            'password_changed_at' => $r['password_changed_at'],
            'previous_login' => false === $previous ? null : $previous,
            'session_id' => $currentSessionId,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function sessions(int $accountId, ?string $currentSessionId = null): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT id, ip, user_agent, created_at, last_seen_at, expires_at
            FROM user_session
            WHERE account_id = ? AND state = 'active' AND revoked_at IS NULL AND expires_at > now()
            ORDER BY last_seen_at DESC
            SQL, [$accountId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'ip' => $r['ip'],
            'user_agent' => $r['user_agent'],
            'created_at' => $r['created_at'],
            'last_seen_at' => $r['last_seen_at'],
            'expires_at' => $r['expires_at'],
            'current' => null !== $currentSessionId && (string) $r['id'] === $currentSessionId,
        ], $rows);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function audit(?string $username, ?string $event, int $page, int $perPage = 50): array
    {
        $where = [];
        $params = [];
        if (null !== $username && '' !== trim($username)) {
            $where[] = '(e.username ILIKE :u OR e.actor ILIKE :u)';
            $params['u'] = '%'.addcslashes(mb_strtolower(trim($username)), '%_\\').'%';
        }
        if (null !== $event && '' !== $event) {
            $where[] = match ($event) {
                'failures' => "e.event IN ('login_failed', 'login_throttled', 'mfa_failed')",
                'admin' => 'e.actor IS NOT NULL',
                default => 'e.event = :e',
            };
            if (!\in_array($event, ['failures', 'admin'], true)) {
                $params['e'] = $event;
            }
        }
        $whereSql = [] === $where ? '' : 'WHERE '.implode(' AND ', $where);
        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);
        $total = (int) $this->db->fetchOne("SELECT count(*) FROM auth_event e $whereSql", $params);
        $items = $this->db->fetchAllAssociative(<<<SQL
            SELECT e.id, e.event, e.username, e.ip, e.user_agent, e.actor, e.details, e.created_at, a.display_name
            FROM auth_event e LEFT JOIN app_account a ON a.id = e.account_id
            $whereSql
            ORDER BY e.id DESC LIMIT :limit OFFSET :offset
            SQL, $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage], ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]);

        return [
            'items' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'details' => json_decode((string) $r['details'], true) ?: []] + $r, $items),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function cast(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'username' => $r['username'],
            'display_name' => $r['display_name'],
            'email' => $r['email'],
            'role' => $r['role'],
            'active' => (bool) $r['active'],
            'must_change_password' => (bool) $r['must_change_password'],
            'mfa' => (bool) $r['mfa'],
            'last_login_at' => $r['last_login_at'],
            'last_login_ip' => $r['last_login_ip'],
            'password_changed_at' => $r['password_changed_at'],
            'created_at' => $r['created_at'],
            'sessions' => (int) $r['sessions'],
            'locked' => $this->throttle->isLocked((string) $r['username']),
        ];
    }
}
