<?php

declare(strict_types=1);

namespace App\Virtualization\Service;

use App\Attachment\Service\AttachmentStorage;
use App\Dcim\Service\Num;
use App\Ui\ReadModel\ManageQuery;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;

/**
 * Clusters de virtualização, tipos e grupos (ADR-0017). Porta de `virtualization.Cluster`/`ClusterType`/
 * `ClusterGroup` (netbox/virtualization/models/clusters.py). Os hosts são equipamentos físicos (dcim_device.cluster_id).
 */
final readonly class ClusterService
{
    public const array STATUS = ['planned' => 'Planejado', 'staging' => 'Em implantação', 'active' => 'Ativo', 'decommissioning' => 'Em desativação', 'offline' => 'Fora do ar'];

    /**
     * Capacidade do host: o que foi informado no equipamento; senão, o inventário do ativo vinculado
     * (threads dos processadores, memória do hardware, soma dos discos).
     */
    public const string HOST_CAPACITY = <<<'SQL'
        coalesce(d.cpu_threads, (SELECT sum(coalesce(nullif(c.attributes->>'threads', '')::int, nullif(c.attributes->>'cores', '')::int))::int
                                   FROM component c WHERE c.asset_id = d.asset_id AND c.kind = 'processor')) AS cpu_threads,
        coalesce(d.memory_mb, (SELECT nullif(a.attributes->>'memory_mib', '')::int FROM asset a WHERE a.id = d.asset_id),
                 (SELECT (sum(c.capacity_mib))::int FROM component c WHERE c.asset_id = d.asset_id AND c.kind = 'memory')) AS memory_mb,
        coalesce(d.storage_gb, (SELECT (sum(c.capacity_mib) / 1024)::int FROM component c WHERE c.asset_id = d.asset_id AND c.kind = 'storage')) AS storage_gb,
        (d.cpu_threads IS NULL AND d.memory_mb IS NULL AND d.storage_gb IS NULL AND d.asset_id IS NOT NULL) AS capacity_from_inventory
        SQL;

    public function __construct(
        private Connection $db,
        private ManageQuery $manage,
        private AttachmentStorage $attachments,
    ) {
    }

    // ------------------------------------------------------------------ tipos e grupos

    /** @return list<array<string, mixed>> */
    public function types(): array
    {
        return $this->db->fetchAllAssociative('SELECT t.id, t.name, t.description, (SELECT count(*) FROM virt_cluster c WHERE c.type_id = t.id) AS clusters FROM virt_cluster_type t ORDER BY t.name');
    }

    /** @return list<array<string, mixed>> */
    public function groups(): array
    {
        return $this->db->fetchAllAssociative('SELECT g.id, g.name, g.description, (SELECT count(*) FROM virt_cluster c WHERE c.group_id = g.id) AS clusters FROM virt_cluster_group g ORDER BY g.name');
    }

    /** @param array<string, mixed> $data */
    public function saveLookup(string $table, ?int $id, array $data): int
    {
        $table = 'group' === $table ? 'virt_cluster_group' : 'virt_cluster_type';
        $p = new Payload($data);
        $name = $p->string('name', 100, required: true);
        $description = $p->string('description', 255);
        if (null !== $name && false !== $this->db->fetchOne("SELECT 1 FROM $table WHERE lower(name) = lower(?) AND id <> ?", [$name, $id ?? 0])) {
            $p->error('name', 'Já existe com esse nome.');
        }
        $p->check();
        if (null === $id) {
            return (int) $this->db->fetchOne("INSERT INTO $table (name, description, created_at, updated_at) VALUES (?, ?, ?, ?) RETURNING id", [$name, $description, Refs::now(), Refs::now()]);
        }
        if (0 === $this->db->update($table, ['name' => $name, 'description' => $description, 'updated_at' => Refs::now()], ['id' => $id])) {
            throw new NotFoundException('Registro não encontrado.');
        }

        return $id;
    }

    public function deleteLookup(string $table, int $id): void
    {
        $col = 'group' === $table ? 'group_id' : 'type_id';
        $table = 'group' === $table ? 'virt_cluster_group' : 'virt_cluster_type';
        $n = (int) $this->db->fetchOne("SELECT count(*) FROM virt_cluster WHERE $col = ?", [$id]);
        if ($n > 0) {
            throw new ValidationException(['id' => \sprintf('Usado por %d cluster(s).', $n)]);
        }
        if (0 === $this->db->executeStatement("DELETE FROM $table WHERE id = ?", [$id])) {
            throw new NotFoundException('Registro não encontrado.');
        }
    }

    // ------------------------------------------------------------------ clusters

    /**
     * @param array{q?: string|null, branch?: int|null, type?: int|null, group?: int|null, id?: int|null} $f
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $f = []): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT c.id, c.name, c.status, c.version, c.management_url, c.description, c.type_id, t.name AS type, c.group_id, g.name AS "group",
                   c.branch_id, b.name AS branch, c.location_id, l.name AS location,
                   (SELECT count(*) FROM dcim_device d WHERE d.cluster_id = c.id) AS hosts,
                   (SELECT count(*) FROM virt_machine v WHERE v.cluster_id = c.id) AS vms,
                   (SELECT count(*) FROM virt_machine v WHERE v.cluster_id = c.id AND v.status = 'active') AS vms_active,
                   (SELECT coalesce(sum(v.vcpus), 0)::float8 FROM virt_machine v WHERE v.cluster_id = c.id AND v.status = 'active') AS vcpus,
                   (SELECT coalesce(sum(v.memory_mb), 0) FROM virt_machine v WHERE v.cluster_id = c.id AND v.status = 'active') AS memory_mb,
                   (SELECT coalesce(sum(v.disk_gb), 0) FROM virt_machine v WHERE v.cluster_id = c.id) AS disk_gb
            FROM virt_cluster c
            JOIN virt_cluster_type t ON t.id = c.type_id
            LEFT JOIN virt_cluster_group g ON g.id = c.group_id
            LEFT JOIN branch b ON b.id = c.branch_id
            LEFT JOIN location l ON l.id = c.location_id
            WHERE (CAST(:branch AS int) IS NULL OR c.branch_id = :branch)
              AND (CAST(:id AS int) IS NULL OR c.id = :id)
              AND (CAST(:type AS int) IS NULL OR c.type_id = :type)
              AND (CAST(:grp AS int) IS NULL OR c.group_id = :grp)
              AND (CAST(:q AS text) IS NULL OR c.name ILIKE '%' || CAST(:q AS text) || '%' OR c.description ILIKE '%' || CAST(:q AS text) || '%')
            ORDER BY c.name
            SQL, ['branch' => $f['branch'] ?? null, 'id' => $f['id'] ?? null, 'type' => $f['type'] ?? null, 'grp' => $f['group'] ?? null, 'q' => $f['q'] ?? null]);
        $caps = $this->capacities(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $r += $caps[(int) $r['id']] ?? ['cap_cpu_threads' => null, 'cap_memory_mb' => null, 'cap_storage_gb' => null];
        }
        unset($r);

        return Num::rows($rows, ['vcpus'], ['hosts', 'vms', 'vms_active', 'memory_mb', 'disk_gb']);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $c = $this->list(['id' => $id])[0] ?? throw new NotFoundException('Cluster não encontrado.');
        $c['comments'] = $this->db->fetchOne('SELECT comments FROM virt_cluster WHERE id = ?', [$id]) ?: null;
        $c['hosts_list'] = $this->hosts($id);
        $c['vm_list'] = Num::rows($this->db->fetchAllAssociative(<<<'SQL'
            SELECT v.id, v.name, v.status, v.platform, v.vmid, v.vcpus::float8 AS vcpus, v.memory_mb, v.disk_gb, v.device_id, d.name AS host, r.name AS role, r.color,
                   (SELECT host(ip.address) FROM ipam_ip_address ip WHERE ip.id = v.primary_ip_id) AS primary_ip
            FROM virt_machine v LEFT JOIN dcim_device d ON d.id = v.device_id LEFT JOIN dcim_device_role r ON r.id = v.role_id
            WHERE v.cluster_id = ? ORDER BY v.name
            SQL, [$id]), ['vcpus']);
        $c['attachments'] = $this->manage->attachments('cluster', $id);

        return $c;
    }

    /** @return list<array<string, mixed>> hosts com capacidade e alocação das VMs ativas */
    public function hosts(int $clusterId): array
    {
        $cap = self::HOST_CAPACITY;

        return Num::rows($this->db->fetchAllAssociative(<<<SQL
            SELECT d.id, d.name, d.status, d.asset_id, a.uuid AS asset_uuid, k.name AS rack, d.position::float8 AS position, l.name AS location,
                   t.model AS device_type, m.name AS manufacturer, (SELECT host(ip.address) FROM ipam_ip_address ip WHERE ip.id = d.primary_ip_id) AS primary_ip,
                   $cap,
                   (SELECT count(*) FROM virt_machine v WHERE v.device_id = d.id) AS vms,
                   (SELECT coalesce(sum(v.vcpus), 0)::float8 FROM virt_machine v WHERE v.device_id = d.id AND v.status = 'active') AS used_vcpus,
                   (SELECT coalesce(sum(v.memory_mb), 0) FROM virt_machine v WHERE v.device_id = d.id AND v.status = 'active') AS used_memory_mb,
                   (SELECT coalesce(sum(v.disk_gb), 0) FROM virt_machine v WHERE v.device_id = d.id) AS used_disk_gb
            FROM dcim_device d
            JOIN dcim_device_type t ON t.id = d.device_type_id JOIN manufacturer m ON m.id = t.manufacturer_id
            LEFT JOIN asset a ON a.id = d.asset_id LEFT JOIN dcim_rack k ON k.id = d.rack_id LEFT JOIN location l ON l.id = d.location_id
            WHERE d.cluster_id = ? ORDER BY d.name
            SQL, [$clusterId]), ['position', 'used_vcpus'], ['cpu_threads', 'memory_mb', 'storage_gb', 'vms', 'used_memory_mb', 'used_disk_gb']);
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $name = $p->string('name', 100, required: true);
        $typeId = $p->int('type_id', 1, required: true);
        $groupId = $p->int('group_id', 1);
        $branchId = $p->int('branch_id', 1);
        $locationId = $p->int('location_id', 1);
        $url = $p->string('management_url', 255);
        if (null !== $url && !preg_match('#^https?://#i', $url)) {
            $p->error('management_url', 'Informe a URL completa, ex.: https://pve01.matriz.local:8006');
        }
        if (null !== $typeId && false === $this->db->fetchOne('SELECT 1 FROM virt_cluster_type WHERE id = ?', [$typeId])) {
            $p->error('type_id', 'Tipo não encontrado.');
        }
        if (null !== $groupId && false === $this->db->fetchOne('SELECT 1 FROM virt_cluster_group WHERE id = ?', [$groupId])) {
            $p->error('group_id', 'Grupo não encontrado.');
        }
        if (null !== $branchId && false === $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ?', [$branchId])) {
            $p->error('branch_id', 'Filial não encontrada.');
        }
        if (null !== $locationId && false === $this->db->fetchOne('SELECT 1 FROM location WHERE id = ? AND branch_id IS NOT DISTINCT FROM ?', [$locationId, $branchId])) {
            $p->error('location_id', 'O local precisa ser da mesma filial.');
        }
        if (null !== $name && false !== $this->db->fetchOne('SELECT 1 FROM virt_cluster WHERE lower(name) = lower(?) AND id <> ?', [$name, $id ?? 0])) {
            $p->error('name', 'Já existe um cluster com esse nome.');
        }
        $p->check();
        $row = ['name' => $name, 'type_id' => $typeId, 'group_id' => $groupId, 'branch_id' => $branchId, 'location_id' => $locationId,
            'status' => $p->enum('status', array_keys(self::STATUS), 'active'), 'version' => $p->string('version', 50), 'management_url' => $url,
            'description' => $p->string('description', 255), 'comments' => $p->string('comments', 10000)];
        $p->check();
        if (null === $id) {
            $cols = implode(', ', array_keys($row));
            $marks = implode(', ', array_fill(0, \count($row) + 2, '?'));

            return (int) $this->db->fetchOne("INSERT INTO virt_cluster ($cols, created_at, updated_at) VALUES ($marks) RETURNING id", [...array_values($row), Refs::now(), Refs::now()]);
        }
        if (0 === $this->db->update('virt_cluster', $row + ['updated_at' => Refs::now()], ['id' => $id])) {
            throw new NotFoundException('Cluster não encontrado.');
        }

        return $id;
    }

    public function delete(int $id): void
    {
        $n = (int) $this->db->fetchOne('SELECT count(*) FROM virt_machine WHERE cluster_id = ?', [$id]);
        if ($n > 0) {
            throw new ValidationException(['id' => \sprintf('O cluster ainda tem %d VM(s). Mova ou exclua antes.', $n)]);
        }
        $this->attachments->deleteAllOf('cluster', $id);
        if (0 === $this->db->executeStatement('DELETE FROM virt_cluster WHERE id = ?', [$id])) {
            throw new NotFoundException('Cluster não encontrado.');
        }
    }

    /** Coloca/tira um equipamento físico do cluster (host). Tirar o host desvincula as VMs dele. */
    public function setHost(int $clusterId, int $deviceId, bool $member): void
    {
        if (false === $this->db->fetchOne('SELECT 1 FROM virt_cluster WHERE id = ?', [$clusterId])) {
            throw new NotFoundException('Cluster não encontrado.');
        }
        $d = $this->db->fetchAssociative('SELECT id, cluster_id FROM dcim_device WHERE id = ?', [$deviceId]) ?: throw new NotFoundException('Equipamento não encontrado.');
        if ($member && null !== $d['cluster_id'] && (int) $d['cluster_id'] !== $clusterId) {
            throw new ValidationException(['device_id' => 'O servidor já é host de outro cluster.']);
        }
        $this->db->transactional(function () use ($clusterId, $deviceId, $member): void {
            if (!$member) {
                $this->db->executeStatement('UPDATE virt_machine SET device_id = NULL, updated_at = ? WHERE device_id = ? AND cluster_id = ?', [Refs::now(), $deviceId, $clusterId]);
            }
            $this->db->executeStatement('UPDATE dcim_device SET cluster_id = ?, updated_at = ? WHERE id = ?', [$member ? $clusterId : null, Refs::now(), $deviceId]);
        });
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{cap_cpu_threads: int|null, cap_memory_mb: int|null, cap_storage_gb: int|null}>
     */
    private function capacities(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $cpu = $mem = $sto = null;
            foreach ($this->hosts($id) as $h) {
                $cpu = null === $h['cpu_threads'] ? $cpu : ($cpu ?? 0) + (int) $h['cpu_threads'];
                $mem = null === $h['memory_mb'] ? $mem : ($mem ?? 0) + (int) $h['memory_mb'];
                $sto = null === $h['storage_gb'] ? $sto : ($sto ?? 0) + (int) $h['storage_gb'];
            }
            $out[$id] = ['cap_cpu_threads' => $cpu, 'cap_memory_mb' => $mem, 'cap_storage_gb' => $sto];
        }

        return $out;
    }
}
