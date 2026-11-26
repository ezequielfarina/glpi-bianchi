<?php

declare(strict_types=1);

namespace App\Identity\Service;

use App\Identity\Entity\UserSession;
use App\Identity\Security\AuthAudit;
use App\Identity\Security\LoginThrottle;
use App\Identity\Security\PasswordHasher;
use App\Identity\Security\SessionManager;
use App\Identity\Security\Totp;
use App\Shared\Security\SecretBox;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fluxo de login (ADR-0011):
 *
 *   senha ok ─┬─ sem 2FA → sessão `active`
 *             └─ com 2FA → sessão `mfa_pending` (5 min) → código TOTP ok → nova sessão `active`
 *
 * Toda resposta de falha é a mesma ("usuário ou senha inválidos"), com o mesmo custo
 * de CPU (Argon2 fictício para login inexistente). Bloqueio progressivo em LoginThrottle.
 * Cada login gera token novo (sem fixação de sessão).
 */
final readonly class LoginService
{
    public const int MFA_MAX_FAILURES = 5;

    public function __construct(
        private Connection $db,
        private PasswordHasher $hasher,
        private LoginThrottle $throttle,
        private SessionManager $sessions,
        private AuthAudit $audit,
        private SecretBox $box,
    ) {
    }

    /**
     * @return array{status: 'ok'|'mfa_required'|'invalid'|'throttled', token?: string, retry_after?: int, must_change_password?: bool}
     */
    public function login(string $username, #[\SensitiveParameter] string $password, Request $request): array
    {
        $username = mb_strtolower(trim($username));
        $ip = $request->getClientIp();

        if ('' === $username || '' === $password || mb_strlen($username) > 64) {
            return ['status' => 'invalid'];
        }
        $wait = $this->throttle->retryAfter($username, $ip);
        if ($wait > 0) {
            $this->audit->log('login_throttled', null, $username, $request, details: ['retry_after' => $wait]);

            return ['status' => 'throttled', 'retry_after' => $wait];
        }

        $account = $this->db->fetchAssociative(
            'SELECT id, password_hash, active, must_change_password, totp_enabled_at FROM app_account WHERE username = ?',
            [$username],
        );
        if (false === $account) {
            $this->hasher->dummyVerify($password);
            $this->audit->log('login_failed', null, $username, $request, details: ['reason' => 'unknown_user']);

            return ['status' => 'invalid'];
        }
        $id = (int) $account['id'];
        if (!$this->hasher->verify((string) $account['password_hash'], $password)) {
            $this->audit->log('login_failed', $id, $username, $request, details: ['reason' => 'bad_password']);

            return ['status' => 'invalid'];
        }
        if (!$account['active']) {
            $this->audit->log('login_failed', $id, $username, $request, details: ['reason' => 'inactive']);

            return ['status' => 'invalid'];
        }
        if ($this->hasher->needsRehash((string) $account['password_hash'])) {
            $this->db->executeStatement('UPDATE app_account SET password_hash = ? WHERE id = ?', [$this->hasher->hash($password), $id]);
        }

        $this->sessions->purge();
        $this->audit->purge();

        if (null !== $account['totp_enabled_at']) {
            // NÃO é login_ok: login_ok zera o bloqueio, o que permitiria tentar códigos sem limite
            $this->audit->log('password_ok', $id, $username, $request, details: ['mfa' => 'pending']);

            return ['status' => 'mfa_required', 'token' => $this->sessions->create($id, UserSession::STATE_MFA_PENDING, $request)];
        }

        return $this->complete($id, $username, $request, false) + ['must_change_password' => (bool) $account['must_change_password']];
    }

    /**
     * Segunda etapa: código TOTP sobre a sessão `mfa_pending` do cookie.
     *
     * @return array{status: 'ok'|'invalid'|'expired', token?: string, must_change_password?: bool, remaining?: int}
     */
    public function verifyMfa(string $pendingToken, string $code, Request $request): array
    {
        $s = $this->sessions->find($pendingToken, UserSession::STATE_MFA_PENDING);
        if (null === $s || null === $s['totp_secret']) {
            return ['status' => 'expired'];
        }
        $accountId = (int) $s['account_id'];
        $step = Totp::verify($this->box->decrypt((string) $s['totp_secret']), $code, null === $s['totp_last_step'] ? null : (int) $s['totp_last_step']);
        if (null === $step) {
            $failures = $this->sessions->countMfaFailure($s['session_id']);
            // conta também para o bloqueio por login/IP
            $this->audit->log('mfa_failed', $accountId, (string) $s['username'], $request, details: ['attempt' => $failures]);
            $this->audit->log('login_failed', $accountId, (string) $s['username'], $request, details: ['reason' => 'bad_totp']);
            if ($failures >= self::MFA_MAX_FAILURES) {
                $this->sessions->revoke($s['session_id']);

                return ['status' => 'expired'];
            }

            return ['status' => 'invalid', 'remaining' => self::MFA_MAX_FAILURES - $failures];
        }
        // anti-replay: grava o passo usado (condicional, à prova de corrida)
        $updated = $this->db->executeStatement(
            'UPDATE app_account SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)',
            [$step, $accountId, $step],
        );
        if (0 === (int) $updated) {
            return ['status' => 'invalid', 'remaining' => 0];
        }
        $this->sessions->revoke($s['session_id']);

        return $this->complete($accountId, (string) $s['username'], $request, true)
            + ['must_change_password' => (bool) $s['must_change_password']];
    }

    public function logout(string $token, Request $request): void
    {
        $s = $this->sessions->find($token);
        if (null !== $s) {
            $this->sessions->revoke($s['session_id']);
            $this->audit->log('logout', (int) $s['account_id'], (string) $s['username'], $request);
        }
    }

    /** @return array{status: 'ok', token: string} */
    private function complete(int $accountId, string $username, Request $request, bool $viaMfa): array
    {
        $token = $this->sessions->create($accountId, UserSession::STATE_ACTIVE, $request);
        $this->db->executeStatement('UPDATE app_account SET last_login_at = now(), last_login_ip = ? WHERE id = ?', [$request->getClientIp(), $accountId]);
        $this->audit->log('login_ok', $accountId, $username, $request, details: $viaMfa ? ['mfa' => 'totp'] : []);

        return ['status' => 'ok', 'token' => $token];
    }
}
