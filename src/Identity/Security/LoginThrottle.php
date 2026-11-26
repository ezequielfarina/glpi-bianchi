<?php

declare(strict_types=1);

namespace App\Identity\Security;

use Doctrine\DBAL\Connection;

/**
 * Bloqueio progressivo contra força bruta, calculado sobre `auth_event`:
 *
 * - por login: 5 falhas em 15 min → bloqueia até 15 min após a última falha
 *   (o contador zera com login bem-sucedido ou desbloqueio pelo administrador);
 * - por IP: 30 falhas em 15 min → bloqueia o IP (password spraying em vários logins).
 *
 * O bloqueio vale para o login digitado mesmo que não exista — não revela quais contas existem.
 */
final readonly class LoginThrottle
{
    public const int MAX_PER_USER = 5;
    public const int MAX_PER_IP = 30;
    public const int WINDOW_MINUTES = 15;

    public function __construct(private Connection $db)
    {
    }

    /** @return int segundos até liberar (0 = liberado) */
    public function retryAfter(string $username, ?string $ip): int
    {
        $user = $this->db->fetchAssociative(<<<'SQL'
            -- ordem pelo id (monotônico): created_at tem precisão de segundos
            WITH reset AS (
                SELECT max(id) AS id FROM auth_event
                WHERE username = :u AND event IN ('login_ok', 'unlock', 'password_reset')
            )
            SELECT count(*) AS failures, max(created_at) AS last
            FROM auth_event, reset
            WHERE username = :u AND event = 'login_failed'
              AND created_at > now() - make_interval(mins => :w)
              AND (reset.id IS NULL OR auth_event.id > reset.id)
            SQL, ['u' => $username, 'w' => self::WINDOW_MINUTES]);
        $wait = 0;
        if (false !== $user && (int) $user['failures'] >= self::MAX_PER_USER) {
            $wait = $this->secondsUntil((string) $user['last']);
        }
        if (null !== $ip) {
            $byIp = $this->db->fetchAssociative(
                "SELECT count(*) AS failures, max(created_at) AS last FROM auth_event
                 WHERE ip = ? AND event = 'login_failed' AND created_at > now() - make_interval(mins => ?)",
                [$ip, self::WINDOW_MINUTES],
            );
            if (false !== $byIp && (int) $byIp['failures'] >= self::MAX_PER_IP) {
                $wait = max($wait, $this->secondsUntil((string) $byIp['last']));
            }
        }

        return $wait;
    }

    /** Login bloqueado agora? (para a tela de usuários) */
    public function isLocked(string $username): bool
    {
        return $this->retryAfter($username, null) > 0;
    }

    private function secondsUntil(string $lastFailure): int
    {
        return max(1, (int) $this->db->fetchOne(
            'SELECT ceil(extract(epoch FROM (?::timestamptz + make_interval(mins => ?)) - now()))::int',
            [$lastFailure, self::WINDOW_MINUTES],
        ));
    }
}
