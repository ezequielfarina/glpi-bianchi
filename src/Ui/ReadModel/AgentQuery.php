<?php

declare(strict_types=1);

namespace App\Ui\ReadModel;

use Doctrine\DBAL\Connection;

/** Agentes e inventários recebidos, para as telas de operação. */
final readonly class AgentQuery
{
    public function __construct(private Connection $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function agents(): array
    {
        return $this->db->fetchAllAssociative(<<<'SQL'
            SELECT ag.device_id, ag.name, ag.version, ag.remote_ip, ag.tag, ag.last_contact_at, ag.created_at,
                   ag.enabled_tasks, a.uuid AS asset_uuid, a.name AS asset_name,
                   r.status AS last_status, r.received_at AS last_inventory_at
            FROM agent ag
            LEFT JOIN asset a ON a.id = ag.asset_id
            LEFT JOIN LATERAL (SELECT status, received_at FROM raw_inventory WHERE agent_id = ag.id ORDER BY id DESC LIMIT 1) r ON true
            ORDER BY ag.last_contact_at DESC
            LIMIT 1000
            SQL);
    }

    /** @return list<array<string, mixed>> */
    public function inventories(?string $status, int $limit = 200): array
    {
        $sql = <<<'SQL'
            SELECT r.id, r.device_id, r.itemtype, r.source_format, r.partial, r.size_bytes, r.status,
                   left(r.error, 500) AS error, r.received_at, r.processed_at, r.duration_ms,
                   a.uuid AS asset_uuid, a.name AS asset_name
            FROM raw_inventory r LEFT JOIN asset a ON a.id = r.asset_id
            SQL;
        $params = [];
        if (null !== $status && '' !== $status) {
            $sql .= ' WHERE r.status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY r.id DESC LIMIT '.max(1, min(1000, $limit));

        return $this->db->fetchAllAssociative($sql, $params);
    }

    /** Payload bruto (JSON) de um inventário — diagnóstico. */
    public function payload(string $id): ?string
    {
        if (!ctype_digit($id)) {
            return null;
        }
        $payload = $this->db->fetchOne('SELECT jsonb_pretty(payload) FROM raw_inventory WHERE id = :id', ['id' => $id]);

        return false === $payload ? null : (string) $payload;
    }
}
