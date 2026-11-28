<?php

declare(strict_types=1);

namespace App\Vpn\Service;

use Doctrine\DBAL\Connection;

/**
 * Casa os dois lados de cada túnel entre FortiGates do inventário (ADR-0022): o remote-gw de A é o IP local
 * de B. Preferência para o par mútuo (B também aponta para A); hub/dial-up aceita só a ida (vários spokes).
 * Sem candidato, mantém o par que já estava (pode ter sido escolhido à mão — WAN com IP dinâmico).
 */
final readonly class Pairing
{
    public function __construct(private Connection $db)
    {
    }

    public function recompute(): void
    {
        $this->db->executeStatement(<<<'SQL'
            UPDATE vpn_tunnel a SET peer_tunnel_id = m.peer, updated_at = now()
            FROM (
                SELECT DISTINCT ON (a.id) a.id, b.id AS peer
                FROM vpn_tunnel a
                JOIN vpn_tunnel b ON b.device_id <> a.device_id AND b.present AND b.local_ip = a.remote_gw
                WHERE a.present AND a.remote_gw IS NOT NULL
                  AND (b.remote_gw = a.local_ip OR b.topology IN ('dialup', 'hub') OR a.local_ip IS NULL)
                ORDER BY a.id, (b.remote_gw = a.local_ip) DESC NULLS LAST, b.id
            ) m
            WHERE a.id = m.id AND a.peer_tunnel_id IS DISTINCT FROM m.peer
            SQL);
        // o lado B de um par mútuo aponta de volta para A
        $this->db->executeStatement(<<<'SQL'
            UPDATE vpn_tunnel b SET peer_tunnel_id = a.id, updated_at = now()
            FROM vpn_tunnel a
            WHERE a.peer_tunnel_id = b.id AND b.peer_tunnel_id IS NULL AND b.remote_gw = a.local_ip AND b.topology NOT IN ('dialup', 'hub')
            SQL);
    }
}
