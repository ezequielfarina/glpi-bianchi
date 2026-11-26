<?php

declare(strict_types=1);

namespace App\Tools\Service;

use Doctrine\DBAL\Connection;

/**
 * Cruza os dispositivos de um scan com o inventário, nesta ordem de confiança:
 *   1. MAC  — portas de rede do agente ou atributo "mac" de ativos manuais;
 *   2. IP   — IPs do agente ou atributo "ip" de ativos manuais (impressoras etc.);
 *   3. nome — NetBIOS ou 1º rótulo do DNS = nome do ativo.
 * Também completa o MAC a partir do inventário quando o scan não o obteve (outra VLAN sem SNMP).
 */
final readonly class ScanCorrelator
{
    public function __construct(private Connection $db)
    {
    }

    public function correlate(int $scanId): void
    {
        // 1. MAC
        $this->db->executeStatement(<<<'SQL'
            UPDATE network_scan_host h SET asset_id = m.asset_id, match_by = 'mac'
            FROM (
                SELECT DISTINCT ON (mac) mac, asset_id FROM (
                    SELECT p.mac_address AS mac, a.id AS asset_id, a.last_inventory_at AS seen
                    FROM network_port p JOIN asset a ON a.id = p.asset_id
                    WHERE a.deleted_at IS NULL AND p.mac_address IS NOT NULL
                    UNION ALL
                    SELECT lower(a.attributes->>'mac'), a.id, a.updated_at FROM asset a
                    WHERE a.deleted_at IS NULL AND a.attributes->>'mac' IS NOT NULL
                ) x ORDER BY mac, seen DESC NULLS LAST
            ) m
            WHERE h.scan_id = :scan AND h.asset_id IS NULL AND h.mac = m.mac
            SQL, ['scan' => $scanId]);

        // 2. IP (+ MAC do inventário quando faltou)
        $this->db->executeStatement(<<<'SQL'
            UPDATE network_scan_host h SET asset_id = m.asset_id, match_by = 'ip',
                   mac = coalesce(h.mac, m.mac), mac_source = CASE WHEN h.mac IS NULL AND m.mac IS NOT NULL THEN 'inventory' ELSE h.mac_source END
            FROM (
                SELECT DISTINCT ON (ip) ip, asset_id, mac FROM (
                    SELECT i.address AS ip, a.id AS asset_id, p.mac_address AS mac, a.last_inventory_at AS seen
                    FROM ip_address i JOIN network_port p ON p.id = i.network_port_id JOIN asset a ON a.id = p.asset_id
                    WHERE a.deleted_at IS NULL AND i.version = 4
                    UNION ALL
                    SELECT a.attributes->>'ip', a.id, lower(a.attributes->>'mac'), a.updated_at FROM asset a
                    WHERE a.deleted_at IS NULL AND a.attributes->>'ip' IS NOT NULL
                ) x ORDER BY ip, seen DESC NULLS LAST
            ) m
            WHERE h.scan_id = :scan AND h.asset_id IS NULL AND h.ip = m.ip
            SQL, ['scan' => $scanId]);

        // 3. nome
        $this->db->executeStatement(<<<'SQL'
            UPDATE network_scan_host h SET asset_id = a.id, match_by = 'name'
            FROM asset a
            WHERE h.scan_id = :scan AND h.asset_id IS NULL AND a.deleted_at IS NULL
              AND lower(a.name) = lower(coalesce(h.netbios_name, split_part(h.hostname, '.', 1)))
              AND coalesce(h.netbios_name, h.hostname) IS NOT NULL
            SQL, ['scan' => $scanId]);
    }
}
