<?php

declare(strict_types=1);

namespace App\Ui\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * Indicadores da tela "Visão geral". SQL agregado direto (read model), sem ORM:
 * uma ida ao banco por bloco, independente do tamanho do parque.
 */
final readonly class OverviewQuery
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, mixed> */
    public function fetch(): array
    {
        $kpi = $this->db->fetchAssociative(<<<'SQL'
            SELECT
              (SELECT count(*) FROM asset WHERE deleted_at IS NULL)                                         AS assets,
              (SELECT count(*) FROM asset WHERE deleted_at IS NULL AND created_at > now() - interval '7 days') AS assets_new_7d,
              (SELECT count(*) FROM asset WHERE deleted_at IS NULL AND dynamic
                                         AND last_inventory_at < now() - interval '7 days')                  AS assets_stale_7d,
              (SELECT count(*) FROM agent)                                                                   AS agents,
              (SELECT count(*) FROM agent WHERE last_contact_at > now() - interval '24 hours')              AS agents_24h,
              (SELECT count(*) FROM raw_inventory WHERE received_at >= date_trunc('day', now()))            AS inventories_today,
              (SELECT coalesce(round(avg(duration_ms)), 0) FROM raw_inventory
                 WHERE status = 'processed' AND received_at >= date_trunc('day', now()))                   AS avg_ms_today,
              (SELECT count(*) FROM raw_inventory WHERE status = 'failed'
                 AND received_at > now() - interval '24 hours')                                             AS failed_24h,
              (SELECT count(*) FROM raw_inventory WHERE status IN ('received', 'processing'))                AS pending,
              (SELECT count(*) FROM software)                                                                AS software
            SQL) ?: [];

        $hourly = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT to_char(h.hour, 'HH24') AS hour,
                   count(r.id) FILTER (WHERE r.status = 'processed')                 AS processed,
                   count(r.id) FILTER (WHERE r.status = 'failed')                    AS failed,
                   count(r.id) FILTER (WHERE r.status NOT IN ('processed', 'failed')) AS other
            FROM generate_series(date_trunc('hour', now()) - interval '23 hours', date_trunc('hour', now()), interval '1 hour') AS h(hour)
            LEFT JOIN raw_inventory r ON r.received_at >= h.hour AND r.received_at < h.hour + interval '1 hour'
            GROUP BY h.hour ORDER BY h.hour
            SQL);

        $os = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT coalesce(os_full_name, os_name, 'Não informado') AS name, count(*) AS count
            FROM asset WHERE deleted_at IS NULL
              AND asset_type_id = (SELECT id FROM asset_type WHERE code = 'computer')
            GROUP BY 1 ORDER BY 2 DESC, 1 LIMIT 6
            SQL);

        $recent = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT a.uuid, a.name, a.serial_number, a.inventory_number,
                   coalesce(a.os_full_name, a.os_name) AS os, a.os_version,
                   m.name AS manufacturer, mo.name AS model, t.code AS type,
                   a.last_inventory_at, ag.version AS agent_version,
                   (SELECT i.address FROM network_port p JOIN ip_address i ON i.network_port_id = p.id
                     WHERE p.asset_id = a.id AND i.version = 4 AND NOT p.virtual
                       AND i.address NOT LIKE '169.254.%'
                     ORDER BY p.id, i.id LIMIT 1) AS ip
            FROM asset a
            JOIN asset_type t ON t.id = a.asset_type_id
            LEFT JOIN manufacturer m ON m.id = a.manufacturer_id
            LEFT JOIN asset_model mo ON mo.id = a.model_id
            LEFT JOIN LATERAL (SELECT version FROM agent WHERE asset_id = a.id ORDER BY last_contact_at DESC LIMIT 1) ag ON true
            WHERE a.deleted_at IS NULL
            ORDER BY a.last_inventory_at DESC NULLS LAST, a.id DESC
            LIMIT 8
            SQL);

        return [
            'kpi' => array_map(static fn (mixed $v): int => (int) $v, $kpi),
            'hourly' => array_map(static fn (array $r): array => [
                'hour' => $r['hour'],
                'processed' => (int) $r['processed'],
                'failed' => (int) $r['failed'],
                'other' => (int) $r['other'],
            ], $hourly),
            'os' => array_map(static fn (array $r): array => ['name' => $r['name'], 'count' => (int) $r['count']], $os),
            'recent' => $recent,
            'generatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
    }
}
