<?php

declare(strict_types=1);

namespace App\Ui\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Lista e detalhe de ativos para a UI.
 *
 * A busca livre (`q`) cobre nome, serial, patrimônio, UUID de hardware, MAC e IP —
 * as consultas de troubleshooting do dia a dia. Nome usa o índice trigram (ILIKE %q%).
 */
final readonly class AssetQuery
{
    private const array SORTS = [
        'name' => 'a.name',
        'lastInventoryAt' => 'a.last_inventory_at',
        'os' => 'coalesce(a.os_full_name, a.os_name)',
        'createdAt' => 'a.created_at',
        'branch' => 'b.name',
    ];

    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function search(?string $q, ?string $type, ?string $stale, int $page, int $perPage, string $sort, string $dir, ?string $branch = null): array
    {
        $where = ['a.deleted_at IS NULL'];
        $params = [];

        if (null !== $q && '' !== trim($q)) {
            $q = trim($q);
            $params['like'] = '%'.addcslashes($q, '%_\\').'%';
            $mac = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $q));
            $params['mac'] = '%'.implode(':', str_split($mac, 2)).'%';
            $where[] = '(a.name ILIKE :like OR a.serial_number ILIKE :like OR a.inventory_number ILIKE :like
                        OR a.hardware_uuid ILIKE :like
                        OR EXISTS (SELECT 1 FROM network_port p LEFT JOIN ip_address i ON i.network_port_id = p.id
                                   WHERE p.asset_id = a.id AND ('.(\strlen($mac) >= 4 ? 'p.mac_address LIKE :mac OR ' : '').'i.address LIKE :like)))';
            if (\strlen($mac) < 4) {
                unset($params['mac']);
            }
        }
        if (null !== $type && '' !== $type) {
            $where[] = 't.code = :type';
            $params['type'] = $type;
        }
        if ('none' === $branch) {
            $where[] = 'a.branch_id IS NULL';
        } elseif (null !== $branch && ctype_digit($branch)) {
            $where[] = 'a.branch_id = :branch';
            $params['branch'] = (int) $branch;
        }
        if ('1' === $stale) {
            $where[] = "a.dynamic AND a.last_inventory_at < now() - interval '7 days'";
        }

        $whereSql = implode(' AND ', $where);
        $orderBy = (self::SORTS[$sort] ?? 'a.name').' '.('desc' === strtolower($dir) ? 'DESC NULLS LAST' : 'ASC NULLS LAST');
        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);

        $total = (int) $this->db->fetchOne("SELECT count(*) FROM asset a JOIN asset_type t ON t.id = a.asset_type_id WHERE $whereSql", $params);

        $items = $this->db->fetchAllAssociative(<<<SQL
            SELECT a.uuid, a.name, a.status, a.serial_number, a.inventory_number,
                   coalesce(a.os_full_name, a.os_name) AS os, a.os_version, a.os_architecture,
                   t.code AS type, t.label AS type_label, m.name AS manufacturer, mo.name AS model,
                   l.name AS location, a.branch_id, b.name AS branch, b.code AS branch_code, a.last_inventory_at, a.dynamic,
                   (a.attributes->>'memory_mib')::int AS memory_mib,
                   (SELECT uuid FROM attachment WHERE owner_type = 'asset' AND owner_id = a.id AND kind = 'photo' ORDER BY created_at DESC LIMIT 1) AS photo_uuid,
                   (SELECT i.address FROM network_port p JOIN ip_address i ON i.network_port_id = p.id
                     WHERE p.asset_id = a.id AND i.version = 4 AND NOT p.virtual AND i.address NOT LIKE '169.254.%'
                     ORDER BY p.id, i.id LIMIT 1) AS ip
            FROM asset a
            JOIN asset_type t ON t.id = a.asset_type_id
            LEFT JOIN manufacturer m ON m.id = a.manufacturer_id
            LEFT JOIN asset_model mo ON mo.id = a.model_id
            LEFT JOIN location l ON l.id = a.location_id
            LEFT JOIN branch b ON b.id = a.branch_id
            WHERE $whereSql
            ORDER BY $orderBy, a.id
            LIMIT :limit OFFSET :offset
            SQL, $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage], ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /**
     * Totais por tipo de ativo (todos os tipos, inclusive zerados), com situação e
     * pendências — base da visão geral e dos contadores dos filtros da lista.
     *
     * @return list<array{type: string, label: string, total: int, in_use: int, in_stock: int, in_repair: int, other: int, stale: int, no_branch: int}>
     */
    public function typeSummary(?string $branch = null): array
    {
        $params = [];
        $branchSql = '';
        if ('none' === $branch) {
            $branchSql = 'AND a.branch_id IS NULL';
        } elseif (null !== $branch && ctype_digit($branch)) {
            $branchSql = 'AND a.branch_id = :branch';
            $params['branch'] = (int) $branch;
        }

        $rows = $this->db->fetchAllAssociative(<<<SQL
            SELECT t.code AS type, t.label,
                   count(a.id) AS total,
                   count(a.id) FILTER (WHERE a.status = 'in_use') AS in_use,
                   count(a.id) FILTER (WHERE a.status = 'in_stock') AS in_stock,
                   count(a.id) FILTER (WHERE a.status = 'in_repair') AS in_repair,
                   count(a.id) FILTER (WHERE a.status NOT IN ('in_use', 'in_stock', 'in_repair')) AS other,
                   count(a.id) FILTER (WHERE a.dynamic AND a.last_inventory_at < now() - interval '7 days') AS stale,
                   count(a.id) FILTER (WHERE a.branch_id IS NULL) AS no_branch
            FROM asset_type t
            LEFT JOIN asset a ON a.asset_type_id = t.id AND a.deleted_at IS NULL $branchSql
            GROUP BY t.id, t.code, t.label
            ORDER BY count(a.id) DESC, t.label
            SQL, $params);

        return array_map(static fn (array $r): array => [
            'type' => (string) $r['type'],
            'label' => (string) $r['label'],
            'total' => (int) $r['total'],
            'in_use' => (int) $r['in_use'],
            'in_stock' => (int) $r['in_stock'],
            'in_repair' => (int) $r['in_repair'],
            'other' => (int) $r['other'],
            'stale' => (int) $r['stale'],
            'no_branch' => (int) $r['no_branch'],
        ], $rows);
    }

    /**
     * Matriz filial × tipo (só combinações com ativos).
     *
     * @return list<array{branch_id: int|null, branch: string|null, branch_code: string|null, kind: string|null, type: string, total: int}>
     */
    public function branchTypeMatrix(): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT b.id AS branch_id, b.name AS branch, b.code AS branch_code, b.kind, t.code AS type, count(*) AS total
            FROM asset a
            JOIN asset_type t ON t.id = a.asset_type_id
            LEFT JOIN branch b ON b.id = a.branch_id
            WHERE a.deleted_at IS NULL
            GROUP BY b.id, b.name, b.code, b.kind, t.code
            ORDER BY b.kind = 'headquarters' DESC NULLS LAST, b.name NULLS LAST, t.code
            SQL);

        return array_map(static fn (array $r): array => [
            'branch_id' => null === $r['branch_id'] ? null : (int) $r['branch_id'],
            'branch' => $r['branch'],
            'branch_code' => $r['branch_code'],
            'kind' => $r['kind'],
            'type' => (string) $r['type'],
            'total' => (int) $r['total'],
        ], $rows);
    }

    /** @return array<string, mixed>|null */
    public function detail(string $uuid): ?array
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
            return null;
        }

        $asset = $this->db->fetchAssociative(<<<'SQL'
            SELECT a.id, a.uuid, a.name, a.status, a.serial_number, a.inventory_number, a.hardware_uuid, a.deleted_at,
                   a.os_name, a.os_full_name, a.os_version, a.os_service_pack, a.os_architecture,
                   a.os_kernel_version, a.os_installed_at, a.attributes, a.dynamic, a.comment,
                   a.last_inventory_at, a.created_at, a.updated_at,
                   t.code AS type, t.label AS type_label, m.name AS manufacturer, mo.name AS model,
                   o.name AS organization, l.name AS location, u.username AS user, g.name AS tech_group,
                   a.branch_id, b.name AS branch, b.code AS branch_code
            FROM asset a
            JOIN asset_type t ON t.id = a.asset_type_id
            JOIN organization o ON o.id = a.organization_id
            LEFT JOIN manufacturer m ON m.id = a.manufacturer_id
            LEFT JOIN asset_model mo ON mo.id = a.model_id
            LEFT JOIN location l ON l.id = a.location_id
            LEFT JOIN branch b ON b.id = a.branch_id
            LEFT JOIN app_user u ON u.id = a.user_id
            LEFT JOIN app_group g ON g.id = a.tech_group_id
            WHERE a.uuid = :uuid
            SQL, ['uuid' => $uuid]);

        if (false === $asset) {
            return null;
        }
        $id = (int) $asset['id'];
        unset($asset['id']);
        $asset['attributes'] = json_decode((string) $asset['attributes'], true) ?: [];

        $components = array_map(
            static function (array $c): array {
                $c['attributes'] = json_decode((string) $c['attributes'], true) ?: [];

                return $c;
            },
            $this->db->fetchAllAssociative(<<<'SQL'
                SELECT c.kind, c.designation, c.serial_number, c.slot, c.capacity_mib, c.frequency_mhz,
                       c.attributes, c.dynamic, m.name AS manufacturer
                FROM component c LEFT JOIN manufacturer m ON m.id = c.manufacturer_id
                WHERE c.asset_id = :id
                ORDER BY array_position(ARRAY['processor','memory','storage','graphic_card','network_card','drive'], c.kind::text), c.slot, c.id
                SQL, ['id' => $id]),
        );

        $software = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT s.name, sv.version, sv.architecture, m.name AS publisher, si.installed_at, si.dynamic, si.first_seen_at
            FROM software_installation si
            JOIN software_version sv ON sv.id = si.software_version_id
            JOIN software s ON s.id = sv.software_id
            LEFT JOIN manufacturer m ON m.id = s.publisher_id
            WHERE si.asset_id = :id
            ORDER BY lower(s.name), sv.version
            SQL, ['id' => $id]);

        $ports = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT p.name, p.mac_address, p.type, p.speed_mbps, p.oper_status, p.virtual, p.dynamic,
                   coalesce(json_agg(json_build_object('address', i.address, 'prefix', i.prefix_length, 'version', i.version)
                            ORDER BY i.version, i.address) FILTER (WHERE i.id IS NOT NULL), '[]') AS ips
            FROM network_port p LEFT JOIN ip_address i ON i.network_port_id = p.id
            WHERE p.asset_id = :id
            GROUP BY p.id ORDER BY p.virtual, p.name
            SQL, ['id' => $id]);
        foreach ($ports as &$port) {
            $port['ips'] = json_decode((string) $port['ips'], true) ?: [];
        }
        unset($port);

        $history = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT field, old_value, new_value, source, actor, created_at
            FROM asset_history WHERE asset_id = :id ORDER BY id DESC LIMIT 200
            SQL, ['id' => $id]);

        $agents = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT device_id, name, version, remote_ip, tag, last_contact_at, enabled_tasks, NULL AS asset_uuid, NULL AS asset_name, NULL AS last_status, NULL AS last_inventory_at
            FROM agent WHERE asset_id = :id ORDER BY last_contact_at DESC
            SQL, ['id' => $id]);

        $attachments = $this->db->fetchAllAssociative(
            "SELECT uuid, kind, filename, mime_type, size_bytes, description, created_by, created_at FROM attachment WHERE owner_type = 'asset' AND owner_id = ? ORDER BY kind DESC, created_at DESC",
            [$id],
        );

        $licenses = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT la.id AS assignment_id, l.id, l.name, l.expires_at, t.name AS license_type
            FROM license_assignment la JOIN license l ON l.id = la.license_id JOIN license_type t ON t.id = l.license_type_id
            WHERE la.asset_id = :id ORDER BY l.name
            SQL, ['id' => $id]);

        $contracts = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT c.id, c.title, c.number, c.end_date, s.name AS supplier
            FROM contract_asset ca JOIN contract c ON c.id = ca.contract_id LEFT JOIN supplier s ON s.id = c.supplier_id
            WHERE ca.asset_id = :id ORDER BY c.end_date NULLS LAST
            SQL, ['id' => $id]);

        $locks = $this->db->fetchFirstColumn('SELECT field FROM locked_field WHERE asset_id = :id ORDER BY field', ['id' => $id]);

        $inventories = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT id, status, outcome, changes, partial, source_format, size_bytes, duration_ms, received_at, left(error, 500) AS error
            FROM raw_inventory WHERE asset_id = :id ORDER BY id DESC LIMIT 20
            SQL, ['id' => $id]);

        return $asset + [
            'components' => $components,
            'software' => $software,
            'networkPorts' => $ports,
            'history' => $history,
            'agents' => $agents,
            'inventories' => $inventories,
            'attachments' => $attachments,
            'photo_uuid' => array_values(array_filter($attachments, static fn (array $f): bool => 'photo' === $f['kind']))[0]['uuid'] ?? null,
            'licenses' => $licenses,
            'contracts' => $contracts,
            'locked_fields' => $locks,
        ];
    }
}
