<?php

declare(strict_types=1);

namespace App\Identity\Service;

use App\Identity\Entity\Account;
use App\Identity\Security\AuthAudit;
use App\Identity\Security\PasswordHasher;
use App\Identity\Security\PasswordPolicy;
use App\Identity\Security\SessionManager;
use App\Identity\Security\Totp;
use App\Shared\Security\SecretBox;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Administração de contas e autoatendimento (senha, 2FA, sessões).
 *
 * Travas de segurança:
 * - sempre resta ao menos 1 administrador ativo; ninguém se rebaixa, se desativa ou se exclui;
 * - reset de senha gera senha temporária exibida uma única vez + troca obrigatória
 *   no próximo login, e encerra todas as sessões da conta;
 * - trocar a própria senha exige a senha atual e encerra as demais sessões;
 * - desativar a conta derruba as sessões na hora;
 * - desligar o próprio 2FA exige senha + código válido.
 */
final readonly class AccountService
{
    public function __construct(
        private Connection $db,
        private PasswordHasher $hasher,
        private PasswordPolicy $policy,
        private SessionManager $sessions,
        private AuthAudit $audit,
        private SecretBox $box,
    ) {
    }

    // ------------------------------------------------------------------ administração

    /**
     * @param array<string, mixed> $data
     *
     * @return array{id: int, temporary_password: string|null}
     */
    public function create(array $data, string $actor, ?Request $request = null): array
    {
        $p = new Payload($data);
        $username = $this->username($p);
        $displayName = $p->string('display_name', 255, required: true);
        $email = $p->email('email');
        $role = $p->enum('role', Account::ROLES, Account::ROLE_VIEWER);
        $password = $p->string('password', 256);
        if (null !== $password && null !== ($err = $this->policy->validate($password, (string) $username, (string) $displayName))) {
            $p->error('password', $err);
        }
        $p->check();

        $temporary = null === $password ? $this->policy->temporary() : null;
        try {
            $id = (int) $this->db->transactional(fn (): int => (int) $this->db->fetchOne(
                'INSERT INTO app_account (username, display_name, email, password_hash, role, active, must_change_password, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, true, true, now(), now()) RETURNING id',
                [$username, $displayName, $email, $this->hasher->hash($password ?? (string) $temporary), $role],
            ));
        } catch (UniqueConstraintViolationException) {
            throw new ValidationException(['username' => 'Já existe uma conta com este login.']);
        }
        $this->audit->log('account_created', $id, $username, $request, $actor, ['role' => $role]);

        return ['id' => $id, 'temporary_password' => $temporary];
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, int $actorId, string $actor, ?Request $request = null): void
    {
        $current = $this->require($id);
        $p = new Payload($data);
        $set = [];
        if ($p->has('display_name')) {
            $set['display_name'] = $p->string('display_name', 255, required: true);
        }
        if ($p->has('email')) {
            $set['email'] = $p->email('email');
        }
        if ($p->has('role')) {
            $set['role'] = $p->enum('role', Account::ROLES, (string) $current['role']);
            if ($id === $actorId && $set['role'] !== $current['role']) {
                $p->error('role', 'Você não pode alterar o seu próprio perfil.');
            }
        }
        if ($p->has('active')) {
            $set['active'] = $p->bool('active', true);
            if ($id === $actorId && !$set['active']) {
                $p->error('active', 'Você não pode desativar a sua própria conta.');
            }
        }
        $p->check();
        if ([] === $set) {
            return;
        }

        $this->db->transactional(function () use ($id, $current, $set): void {
            $losesAdmin = Account::ROLE_ADMIN === $current['role'] && $current['active']
                && ((isset($set['role']) && Account::ROLE_ADMIN !== $set['role']) || (isset($set['active']) && !$set['active']));
            if ($losesAdmin) {
                $this->assertAnotherAdmin($id);
            }
            $this->db->update('app_account', $set + ['updated_at' => date(\DATE_ATOM)], ['id' => $id], ['active' => ParameterType::BOOLEAN]);
        });

        $username = (string) $current['username'];
        if (isset($set['active']) && $set['active'] !== (bool) $current['active']) {
            if (!$set['active']) {
                $this->sessions->revokeAll($id);
            }
            $this->audit->log($set['active'] ? 'account_enabled' : 'account_disabled', $id, $username, $request, $actor);
        }
        $changes = array_diff_key($set, ['active' => 1]);
        if ([] !== $changes) {
            if (isset($changes['role']) && $changes['role'] !== $current['role']) {
                $this->sessions->revokeAll($id); // novo perfil vale a partir do próximo login
            }
            $this->audit->log('account_updated', $id, $username, $request, $actor, ['fields' => array_keys($changes)]);
        }
    }

    public function delete(int $id, int $actorId, string $actor, ?Request $request = null): void
    {
        $current = $this->require($id);
        if ($id === $actorId) {
            throw new ValidationException(['account' => 'Você não pode excluir a sua própria conta.']);
        }
        $this->db->transactional(function () use ($id, $current): void {
            if (Account::ROLE_ADMIN === $current['role'] && $current['active']) {
                $this->assertAnotherAdmin($id);
            }
            $this->db->executeStatement('DELETE FROM app_account WHERE id = ?', [$id]);
        });
        $this->audit->log('account_deleted', null, (string) $current['username'], $request, $actor);
    }

    /** @return string senha temporária (exibir uma única vez) */
    public function resetPassword(int $id, string $actor, ?Request $request = null): string
    {
        $current = $this->require($id);
        $temporary = $this->policy->temporary();
        $this->db->executeStatement(
            'UPDATE app_account SET password_hash = ?, must_change_password = true, password_changed_at = now(), updated_at = now() WHERE id = ?',
            [$this->hasher->hash($temporary), $id],
        );
        $this->sessions->revokeAll($id);
        $this->audit->log('password_reset', $id, (string) $current['username'], $request, $actor);

        return $temporary;
    }

    public function resetMfa(int $id, string $actor, ?Request $request = null): void
    {
        $current = $this->require($id);
        $this->db->executeStatement('UPDATE app_account SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, updated_at = now() WHERE id = ?', [$id]);
        $this->sessions->revokeAll($id);
        $this->audit->log('mfa_reset', $id, (string) $current['username'], $request, $actor);
    }

    public function unlock(int $id, string $actor, ?Request $request = null): void
    {
        $current = $this->require($id);
        $this->audit->log('unlock', $id, (string) $current['username'], $request, $actor);
    }

    public function revokeSessions(int $id, string $actor, ?Request $request = null): int
    {
        $current = $this->require($id);
        $n = $this->sessions->revokeAll($id);
        $this->audit->log('sessions_revoked', $id, (string) $current['username'], $request, $actor, ['count' => $n]);

        return $n;
    }

    // ------------------------------------------------------------------ autoatendimento

    public function changeOwnPassword(int $id, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new, string $keepSessionId, ?Request $request = null): void
    {
        $acc = $this->require($id);
        if (!$this->hasher->verify((string) $acc['password_hash'], $current)) {
            $this->audit->log('login_failed', $id, (string) $acc['username'], $request, details: ['reason' => 'bad_current_password']);
            throw new ValidationException(['current_password' => 'Senha atual incorreta.']);
        }
        if (null !== ($err = $this->policy->validate($new, (string) $acc['username'], (string) $acc['display_name']))) {
            throw new ValidationException(['new_password' => $err]);
        }
        if ($this->hasher->verify((string) $acc['password_hash'], $new)) {
            throw new ValidationException(['new_password' => 'A nova senha deve ser diferente da atual.']);
        }
        $this->db->executeStatement(
            'UPDATE app_account SET password_hash = ?, must_change_password = false, password_changed_at = now(), updated_at = now() WHERE id = ?',
            [$this->hasher->hash($new), $id],
        );
        $this->sessions->revokeAll($id, $keepSessionId);
        $this->audit->log('password_changed', $id, (string) $acc['username'], $request);
    }

    /** @return array{secret: string, uri: string} segredo novo (pendente até confirmar) */
    public function startMfaSetup(int $id): array
    {
        $acc = $this->require($id);
        if (null !== $acc['totp_enabled_at']) {
            throw new ValidationException(['mfa' => 'A verificação em duas etapas já está ativa.']);
        }
        $secret = Totp::generateSecret();
        $this->db->executeStatement('UPDATE app_account SET totp_secret = ?, totp_last_step = NULL, updated_at = now() WHERE id = ?', [$this->box->encrypt($secret), $id]);

        return ['secret' => $secret, 'uri' => Totp::uri($secret, (string) $acc['username'])];
    }

    public function confirmMfa(int $id, string $code, ?Request $request = null): void
    {
        $acc = $this->require($id);
        if (null !== $acc['totp_enabled_at'] || null === $acc['totp_secret']) {
            throw new ValidationException(['code' => 'Gere o QR code novamente.']);
        }
        $step = Totp::verify($this->box->decrypt((string) $acc['totp_secret']), $code, null);
        if (null === $step) {
            throw new ValidationException(['code' => 'Código inválido. Confira a hora do celular e tente o código atual.']);
        }
        $this->db->executeStatement('UPDATE app_account SET totp_enabled_at = now(), totp_last_step = ?, updated_at = now() WHERE id = ?', [$step, $id]);
        $this->audit->log('mfa_enabled', $id, (string) $acc['username'], $request);
    }

    public function disableOwnMfa(int $id, #[\SensitiveParameter] string $password, string $code, ?Request $request = null): void
    {
        $acc = $this->require($id);
        if (null === $acc['totp_enabled_at']) {
            return;
        }
        $errors = [];
        if (!$this->hasher->verify((string) $acc['password_hash'], $password)) {
            $errors['password'] = 'Senha incorreta.';
        }
        $step = Totp::verify($this->box->decrypt((string) $acc['totp_secret']), $code, null === $acc['totp_last_step'] ? null : (int) $acc['totp_last_step']);
        if (null === $step) {
            $errors['code'] = 'Código inválido.';
        }
        if ([] !== $errors) {
            $this->audit->log('login_failed', $id, (string) $acc['username'], $request, details: ['reason' => 'mfa_disable_denied']);
            throw new ValidationException($errors);
        }
        $this->db->executeStatement('UPDATE app_account SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, updated_at = now() WHERE id = ?', [$id]);
        $this->audit->log('mfa_disabled', $id, (string) $acc['username'], $request);
    }

    public function revokeOwnSession(int $accountId, int $sessionId): void
    {
        $n = $this->db->executeStatement('UPDATE user_session SET revoked_at = now() WHERE id = ? AND account_id = ? AND revoked_at IS NULL', [$sessionId, $accountId]);
        if (0 === (int) $n) {
            throw new NotFoundException('Sessão não encontrada.');
        }
    }

    // ------------------------------------------------------------------ CLI / bootstrap

    /** Usado por app:user:create. @return array{id: int, temporary_password: string|null} */
    public function createFromCli(string $username, string $displayName, string $role, #[\SensitiveParameter] ?string $password): array
    {
        $r = $this->create(['username' => $username, 'display_name' => $displayName, 'role' => $role, 'password' => $password], 'console');
        if (null !== $password) {
            // senha escolhida por quem está no console: não força troca
            $this->db->executeStatement('UPDATE app_account SET must_change_password = false, password_changed_at = now() WHERE id = ?', [$r['id']]);
        }

        return $r;
    }

    public function idByUsername(string $username): ?int
    {
        $id = $this->db->fetchOne('SELECT id FROM app_account WHERE username = ?', [mb_strtolower(trim($username))]);

        return false === $id ? null : (int) $id;
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, mixed> */
    private function require(int $id): array
    {
        return $this->db->fetchAssociative('SELECT * FROM app_account WHERE id = ?', [$id]) ?: throw new NotFoundException('Conta não encontrada.');
    }

    private function assertAnotherAdmin(int $exceptId): void
    {
        // trava as linhas de admins ativos: duas remoções concorrentes não zeram os administradores
        $others = $this->db->fetchFirstColumn("SELECT id FROM app_account WHERE role = 'admin' AND active AND id <> ? FOR UPDATE", [$exceptId]);
        if ([] === $others) {
            throw new ValidationException(['role' => 'É preciso manter pelo menos um administrador ativo.']);
        }
    }

    private function username(Payload $p): ?string
    {
        $u = $p->string('username', 64, required: true);
        if (null === $u) {
            return null;
        }
        $u = mb_strtolower($u);
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $u)) {
            $p->error('username', 'De 3 a 64 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado (ex.: maria.souza).');
        }

        return $u;
    }
}
