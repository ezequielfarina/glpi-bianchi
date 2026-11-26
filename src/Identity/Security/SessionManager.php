<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Identity\Entity\UserSession;
use App\Settings\Service\Settings;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sessões do painel guardadas no PostgreSQL (ADR-0011).
 *
 * - token de 256 bits (CSPRNG) só no cookie; no banco vai o SHA-256 (busca por igualdade, sem timing útil);
 * - cookie `HttpOnly` (JS não lê), `SameSite=Strict`, `Secure` sob HTTPS, `Path=/api/ui`;
 * - expira por inatividade (configurável, padrão 30 min) e por idade máxima (padrão 12 h);
 * - etapa 2FA (`mfa_pending`) vale 5 min e não dá acesso a nada além de /auth/mfa;
 * - revogação imediata: logout, troca/reset de senha, conta desativada, administrador.
 */
final readonly class SessionManager
{
    public const string COOKIE = 'bianchi_sid';
    public const int MFA_PENDING_SECONDS = 300;
    private const int TOUCH_EVERY_SECONDS = 60;

    public function __construct(
        private Connection $db,
        private Settings $settings,
    ) {
    }

    /** @return string token bruto (vai só para o cookie) */
    public function create(int $accountId, string $state, Request $request): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ttl = UserSession::STATE_MFA_PENDING === $state ? self::MFA_PENDING_SECONDS : $this->maxHours() * 3600;
        $this->db->executeStatement(
            'INSERT INTO user_session (account_id, token_hash, state, ip, user_agent, mfa_failures, created_at, last_seen_at, expires_at)
             VALUES (?, ?, ?, ?, ?, 0, now(), now(), now() + make_interval(secs => ?))',
            [$accountId, self::hash($token), $state, $request->getClientIp(), mb_substr((string) $request->headers->get('User-Agent'), 0, 512), $ttl],
        );

        return $token;
    }

    /**
     * Sessão válida para o token, com a conta (ativa). Atualiza "visto por último".
     *
     * @return array<string, mixed>|null
     */
    public function find(string $token, string $state = UserSession::STATE_ACTIVE): ?array
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        $row = $this->db->fetchAssociative(<<<'SQL'
            SELECT s.id AS session_id, s.state, s.mfa_failures, extract(epoch FROM now() - s.last_seen_at)::int AS idle_seconds,
                   a.id AS account_id, a.username, a.display_name, a.role, a.must_change_password, a.totp_enabled_at,
                   a.totp_secret, a.totp_last_step
            FROM user_session s JOIN app_account a ON a.id = s.account_id
            WHERE s.token_hash = :hash AND s.state = :state AND s.revoked_at IS NULL
              AND s.expires_at > now() AND a.active
              AND (s.state = 'mfa_pending' OR s.last_seen_at > now() - make_interval(mins => :idle))
            SQL, ['hash' => self::hash($token), 'state' => $state, 'idle' => $this->idleMinutes()]);
        if (false === $row) {
            return null;
        }
        if ((int) $row['idle_seconds'] >= self::TOUCH_EVERY_SECONDS) {
            $this->db->executeStatement('UPDATE user_session SET last_seen_at = now() WHERE id = ?', [$row['session_id']]);
        }

        return $row;
    }

    public function revoke(int|string $sessionId): void
    {
        $this->db->executeStatement('UPDATE user_session SET revoked_at = now() WHERE id = ? AND revoked_at IS NULL', [$sessionId]);
    }

    /** @return int sessões encerradas */
    public function revokeAll(int $accountId, int|string|null $exceptSessionId = null): int
    {
        return (int) $this->db->executeStatement(
            'UPDATE user_session SET revoked_at = now() WHERE account_id = ? AND revoked_at IS NULL AND id IS DISTINCT FROM ?',
            [$accountId, null === $exceptSessionId ? null : (int) $exceptSessionId],
        );
    }

    public function countMfaFailure(int|string $sessionId): int
    {
        return (int) $this->db->fetchOne('UPDATE user_session SET mfa_failures = mfa_failures + 1 WHERE id = ? RETURNING mfa_failures', [$sessionId]);
    }

    /** Limpeza oportunista (chamada no login): sessões mortas há mais de 7 dias. */
    public function purge(): void
    {
        $this->db->executeStatement("DELETE FROM user_session WHERE (expires_at < now() - interval '7 days') OR (revoked_at < now() - interval '7 days')");
    }

    public function cookie(Request $request, string $token): Cookie
    {
        return Cookie::create(self::COOKIE, $token)
            ->withPath('/api/ui')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    public function clearCookie(Request $request): Cookie
    {
        return $this->cookie($request, '')->withExpires(1);
    }

    public function idleMinutes(): int
    {
        return (int) $this->settings->get(Settings::SESSION_IDLE_MINUTES);
    }

    public function maxHours(): int
    {
        return (int) $this->settings->get(Settings::SESSION_MAX_HOURS);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
