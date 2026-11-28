<?php

declare(strict_types=1);

namespace App\Virtualization\Service;

use App\Attachment\Service\AttachmentStorage;
use App\Dcim\Service\NamePattern;
use App\Dcim\Service\Num;
use App\Dcim\Service\PortRules;
use App\Ui\ReadModel\ManageQuery;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Máquinas virtuais, interfaces e discos (ADR-0017). Porta de `virtualization.VirtualMachine`, `VMInterface` e
 * `VirtualDisk` (netbox/virtualization/models/virtualmachines.py):
 *  - o host precisa pertencer ao cluster da VM (VirtualMachine.clean);
 *  - com discos cadastrados, o disco total é a soma deles (VirtualMachine.disk = sum(virtualdisks));
 *  - IP primário precisa estar numa interface da própria VM.
 */
final readonly class VmService
{
    public const array STATUS = ['active' => 'Ligada', 'offline' => 'Desligada', 'planned' => 'Planejada', 'staged' => 'Em preparação', 'failed' => 'Com falha',
        'decommissioning' => 'Em desativação'];

    public function __construct(
        private Connection $db,
        private ManageQuery $manage,
        private AttachmentStorage $attachments,
    ) {
    }

    /**
     * @param array{q?: string|null, cluster?: int|null, host?: int|null, branch?: int|null, status?: string|null, id?: int|null} $f
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $f = []): array
    {
        return Num::rows($this->db->fetchAllAssociative(<<<'SQL'
            SELECT v.id, v.name, v.status, v.platform, v.vmid, v.vcpus::float8 AS vcpus, v.memory_mb, v.disk_gb, v.description,
                   v.cluster_id, c.name AS cluster, ct.name AS cluster_type, c.management_url, v.device_id, d.name AS host,
                   c.branch_id, b.name AS branch, v.role_id, r.name AS role, r.color, a.uuid AS asset_uuid, a.name AS asset_name,
                   v.primary_ip_id, (SELECT host(ip.address) || '/' || masklen(ip.address) FROM ipam_ip_address ip WHERE ip.id = v.primary_ip_id) AS primary_ip,
                   (SELECT count(*) FROM virt_interface i WHERE i.vm_id = v.id) AS interfaces,
                   (SELECT x.uuid FROM attachment x WHERE x.owner_type = 'vm' AND x.owner_id = v.id AND x.kind = 'photo' ORDER BY x.created_at LIMIT 1) AS photo
            FROM virt_machine v
            JOIN virt_cluster c ON c.id = v.cluster_id
            JOIN virt_cluster_type ct ON ct.id = c.type_id
            LEFT JOIN dcim_device d ON d.id = v.device_id
            LEFT JOIN branch b ON b.id = coalesce(c.branch_id, d.branch_id)
            LEFT JOIN dcim_device_role r ON r.id = v.role_id
            LEFT JOIN asset a ON a.id = v.asset_id
            WHERE (CAST(:id AS int) IS NULL OR v.id = :id)
              AND (CAST(:cluster AS int) IS NULL OR v.cluster_id = :cluster)
              AND (CAST(:host AS int) IS NULL OR v.device_id = :host)
              AND (CAST(:branch AS int) IS NULL OR c.branch_id = :branch OR d.branch_id = :branch)
              AND (CAST(:status AS text) IS NULL OR v.status = :status)
              AND (CAST(:q AS text) IS NULL OR v.name ILIKE '%' || CAST(:q AS text) || '%' OR v.platform ILIKE '%' || CAST(:q AS text) || '%'
                   OR v.vmid = CAST(:q AS text) OR v.description ILIKE '%' || CAST(:q AS text) || '%'
                   OR EXISTS (SELECT 1 FROM virt_interface i JOIN ipam_ip_address ip ON ip.vm_interface_id = i.id WHERE i.vm_id = v.id AND host(ip.address) LIKE CAST(:q AS text) || '%'))
            ORDER BY c.name, v.name
            LIMIT 5000
            SQL, ['id' => $f['id'] ?? null, 'cluster' => $f['cluster'] ?? null, 'host' => $f['host'] ?? null, 'branch' => $f['branch'] ?? null,
            'status' => $f['status'] ?? null, 'q' => $f['q'] ?? null]), ['vcpus'], ['memory_mb', 'disk_gb', 'interfaces']);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $v = $this->list(['id' => $id])[0] ?? throw new NotFoundException('Máquina virtual não encontrada.');
        $v['comments'] = $this->db->fetchOne('SELECT comments FROM virt_machine WHERE id = ?', [$id]) ?: null;
        $v['interfaces_list'] = $this->interfaces($id);
        $v['disks'] = $this->db->fetchAllAssociative('SELECT id, name, size_gb, storage, description FROM virt_disk WHERE vm_id = ? ORDER BY name', [$id]);
        $v['ips'] = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT ip.id, host(ip.address) || '/' || masklen(ip.address) AS address, ip.status, ip.role, ip.dns_name, i.id AS vm_interface_id, i.name AS interface
            FROM ipam_ip_address ip JOIN virt_interface i ON i.id = ip.vm_interface_id WHERE i.vm_id = ? ORDER BY ip.address
            SQL, [$id]);
        $v['attachments'] = $this->manage->attachments('vm', $id);

        return $v;
    }

    /** @return list<array<string, mixed>> */
    public function interfaces(int $vmId): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT i.id, i.name, i.enabled, i.mac_address, i.mtu, i.mode, i.bridge, i.description, i.untagged_vlan_id, uv.vid AS untagged_vid, uv.name AS untagged_vlan,
                   (SELECT string_agg(v.vid::text, ',' ORDER BY v.vid) FROM virt_interface_vlan x JOIN ipam_vlan v ON v.id = x.vlan_id WHERE x.vm_interface_id = i.id) AS tagged_vids,
                   (SELECT json_agg(x.vlan_id) FROM virt_interface_vlan x WHERE x.vm_interface_id = i.id) AS tagged_vlan_ids,
                   (SELECT string_agg(host(ip.address) || '/' || masklen(ip.address), ', ' ORDER BY ip.address) FROM ipam_ip_address ip WHERE ip.vm_interface_id = i.id) AS ips
            FROM virt_interface i LEFT JOIN ipam_vlan uv ON uv.id = i.untagged_vlan_id
            WHERE i.vm_id = ?
            SQL, [$vmId]);
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));
        foreach ($rows as &$r) {
            $r['tagged_vlan_ids'] = null === $r['tagged_vlan_ids'] ? [] : json_decode((string) $r['tagged_vlan_ids'], true);
        }
        unset($r);

        return $rows;
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $name = $p->string('name', 100, required: true);
        $clusterId = $p->int('cluster_id', 1, required: true);
        $deviceId = $p->int('device_id', 1);
        $roleId = $p->int('role_id', 1);
        $assetUuid = $p->string('asset_uuid', 36);
        $primaryIpId = $p->int('primary_ip_id', 1);
        $vcpusRaw = $p->string('vcpus', 10);
        $vcpus = null;
        if (null !== $vcpusRaw) {
            $n = str_replace(',', '.', $vcpusRaw);
            if (!is_numeric($n) || (float) $n <= 0 || (float) $n > 9999) {
                $p->error('vcpus', 'vCPU deve ser maior que zero (aceita fração, ex.: 0,5).');
            } else {
                $vcpus = round((float) $n, 2);
            }
        }
        $memory = $p->int('memory_mb', 1, 100_000_000);
        $disk = $p->int('disk_gb', 0, 100_000_000);
        if (null !== $clusterId && false === $this->db->fetchOne('SELECT 1 FROM virt_cluster WHERE id = ?', [$clusterId])) {
            $p->error('cluster_id', 'Cluster não encontrado.');
        }
        if (null !== $deviceId && false === $this->db->fetchOne('SELECT 1 FROM dcim_device WHERE id = ? AND cluster_id IS NOT DISTINCT FROM ?', [$deviceId, $clusterId])) {
            $p->error('device_id', 'O host precisa ser um servidor deste cluster (adicione-o na tela do cluster).');
        }
        $roleId ??= ($this->db->fetchOne("SELECT id FROM dcim_device_role WHERE name = 'Máquina virtual'") ?: null);
        if (null !== $roleId && false === $this->db->fetchOne('SELECT 1 FROM dcim_device_role WHERE id = ?', [$roleId])) {
            $p->error('role_id', 'Função não encontrada.');
        }
        if (null !== $name && null !== $clusterId && false !== $this->db->fetchOne('SELECT 1 FROM virt_machine WHERE cluster_id = ? AND lower(name) = lower(?) AND id <> ?', [$clusterId, $name, $id ?? 0])) {
            $p->error('name', 'Já existe uma VM com esse nome neste cluster.');
        }
        $assetId = null;
        if (null !== $assetUuid) {
            $assetId = $this->db->fetchOne('SELECT id FROM asset WHERE uuid = CAST(? AS uuid) AND deleted_at IS NULL', [$assetUuid]);
            if (false === $assetId) {
                $p->error('asset_uuid', 'Ativo não encontrado.');
                $assetId = null;
            } else {
                $assetId = (int) $assetId;
                $other = $this->db->fetchOne('SELECT name FROM virt_machine WHERE asset_id = ? AND id <> ?', [$assetId, $id ?? 0]);
                if (false !== $other) {
                    $p->error('asset_uuid', \sprintf('O ativo já está vinculado à VM "%s".', $other));
                }
            }
        }
        if (null !== $primaryIpId && (null === $id || false === $this->db->fetchOne(
            'SELECT 1 FROM ipam_ip_address ip JOIN virt_interface i ON i.id = ip.vm_interface_id WHERE ip.id = ? AND i.vm_id = ?', [$primaryIpId, $id],
        ))) {
            $p->error('primary_ip_id', 'O IP primário precisa estar numa interface desta VM.');
        }
        if (null !== $id && false !== $this->db->fetchOne('SELECT 1 FROM virt_disk WHERE vm_id = ? LIMIT 1', [$id])) {
            $disk = (int) $this->db->fetchOne('SELECT coalesce(sum(size_gb), 0) FROM virt_disk WHERE vm_id = ?', [$id]); // soma dos discos manda
        }
        $p->check();
        $row = ['name' => $name, 'cluster_id' => $clusterId, 'device_id' => $deviceId, 'role_id' => $roleId, 'asset_id' => $assetId, 'primary_ip_id' => $primaryIpId,
            'status' => $p->enum('status', array_keys(self::STATUS), 'active'), 'platform' => $p->string('platform', 100), 'vmid' => $p->string('vmid', 64),
            'vcpus' => $vcpus, 'memory_mb' => $memory, 'disk_gb' => $disk, 'description' => $p->string('description', 255), 'comments' => $p->string('comments', 10000)];
        $copy = $p->bool('copy_interfaces');
        $p->check();

        return $this->db->transactional(function () use ($id, $row, $copy, $assetId): int {
            if (null === $id) {
                $cols = implode(', ', array_keys($row));
                $marks = implode(', ', array_fill(0, \count($row) + 2, '?'));
                $id = (int) $this->db->fetchOne("INSERT INTO virt_machine ($cols, created_at, updated_at) VALUES ($marks) RETURNING id", [...array_values($row), Refs::now(), Refs::now()]);
                if ($copy && null !== $assetId) {
                    $now = Refs::now();
                    $this->db->executeStatement(<<<'SQL'
                        INSERT INTO virt_interface (vm_id, name, mac_address, created_at, updated_at)
                        SELECT ?, left(np.name, 64), np.mac_address, ?, ? FROM network_port np WHERE np.asset_id = ? AND NOT np.virtual
                        ON CONFLICT DO NOTHING
                        SQL, [$id, $now, $now, $assetId]);
                }

                return $id;
            }
            $this->db->update('virt_machine', $row + ['updated_at' => Refs::now()], ['id' => $id]);

            return $id;
        });
    }

    public function delete(int $id): void
    {
        $this->attachments->deleteAllOf('vm', $id);
        if (0 === $this->db->executeStatement('DELETE FROM virt_machine WHERE id = ?', [$id])) {
            throw new NotFoundException('Máquina virtual não encontrada.');
        }
    }

    /**
     * Ativos do inventário ainda sem VM (prioriza os que o agente identificou como virtuais).
     *
     * @return list<array<string, mixed>>
     */
    public function assetCandidates(?string $q): array
    {
        return $this->db->fetchAllAssociative(<<<'SQL'
            SELECT a.uuid, a.name, a.serial_number, a.inventory_number, t.label AS type, b.name AS branch, a.attributes->>'vm_system' AS vm_system,
                   (a.attributes->>'memory_mib')::text AS memory_mib,
                   (SELECT count(*) FROM network_port np WHERE np.asset_id = a.id AND NOT np.virtual) AS interfaces
            FROM asset a JOIN asset_type t ON t.id = a.asset_type_id LEFT JOIN branch b ON b.id = a.branch_id
            WHERE a.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM virt_machine v WHERE v.asset_id = a.id)
              AND (CAST(:q AS text) IS NULL OR a.name ILIKE '%' || CAST(:q AS text) || '%' OR a.serial_number ILIKE '%' || CAST(:q AS text) || '%')
            ORDER BY (a.attributes->>'vm_system') IS NULL OR a.attributes->>'vm_system' IN ('', 'Physical'), a.name
            LIMIT 30
            SQL, ['q' => $q]);
    }

    // ------------------------------------------------------------------ interfaces

    /**
     * @param array<string, mixed> $data
     *
     * @return list<int>
     */
    public function addInterfaces(int $vmId, array $data): array
    {
        if (false === $this->db->fetchOne('SELECT 1 FROM virt_machine WHERE id = ?', [$vmId])) {
            throw new NotFoundException('Máquina virtual não encontrada.');
        }
        $p = new Payload($data);
        $pattern = $p->string('name', 120, required: true);
        $p->check();
        $names = NamePattern::expand((string) $pattern);
        $f = $this->ifaceFields($p, \count($names) > 1);
        $p->check();

        return $this->db->transactional(function () use ($vmId, $names, $f): array {
            $ids = [];
            foreach ($names as $name) {
                $this->assertFreeIface($vmId, $name);
                $ids[] = (int) $this->db->fetchOne(
                    'INSERT INTO virt_interface (vm_id, name, enabled, mac_address, mtu, mode, untagged_vlan_id, bridge, description, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                    [$vmId, $name, $f['enabled'] ? 'true' : 'false', $f['mac_address'], $f['mtu'], $f['mode'], $f['untagged_vlan_id'], $f['bridge'], $f['description'], Refs::now(), Refs::now()],
                );
                $this->setTagged((int) end($ids), $f['tagged']);
            }

            return $ids;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateInterface(int $id, array $data): void
    {
        $i = $this->db->fetchAssociative('SELECT id, vm_id FROM virt_interface WHERE id = ?', [$id]) ?: throw new NotFoundException('Interface não encontrada.');
        $p = new Payload($data);
        $name = $p->string('name', 64, required: true);
        $f = $this->ifaceFields($p, false);
        $p->check();
        $this->assertFreeIface((int) $i['vm_id'], (string) $name, $id);
        $this->db->transactional(function () use ($id, $name, $f): void {
            $this->db->update('virt_interface', ['name' => $name, 'enabled' => $f['enabled'] ? 'true' : 'false', 'mac_address' => $f['mac_address'], 'mtu' => $f['mtu'],
                'mode' => $f['mode'], 'untagged_vlan_id' => $f['untagged_vlan_id'], 'bridge' => $f['bridge'], 'description' => $f['description'], 'updated_at' => Refs::now()], ['id' => $id]);
            $this->setTagged($id, $f['tagged']);
        });
    }

    public function deleteInterface(int $id): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM virt_interface WHERE id = ?', [$id])) {
            throw new NotFoundException('Interface não encontrada.');
        }
    }

    // ------------------------------------------------------------------ discos

    /** @param array<string, mixed> $data */
    public function saveDisk(int $vmId, ?int $id, array $data): int
    {
        if (false === $this->db->fetchOne('SELECT 1 FROM virt_machine WHERE id = ?', [$vmId])) {
            throw new NotFoundException('Máquina virtual não encontrada.');
        }
        $p = new Payload($data);
        $name = $p->string('name', 64, required: true);
        $size = $p->int('size_gb', 0, 100_000_000, required: true);
        if (null !== $name && false !== $this->db->fetchOne('SELECT 1 FROM virt_disk WHERE vm_id = ? AND name = ? AND id <> ?', [$vmId, $name, $id ?? 0])) {
            $p->error('name', 'Já existe um disco com esse nome nesta VM.');
        }
        $p->check();
        $row = ['name' => $name, 'size_gb' => $size, 'storage' => $p->string('storage', 100), 'description' => $p->string('description', 255)];
        $p->check();

        return $this->db->transactional(function () use ($vmId, $id, $row): int {
            if (null === $id) {
                $id = (int) $this->db->fetchOne('INSERT INTO virt_disk (vm_id, name, size_gb, storage, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
                    [$vmId, ...array_values($row), Refs::now(), Refs::now()]);
            } elseif (0 === $this->db->update('virt_disk', $row + ['updated_at' => Refs::now()], ['id' => $id, 'vm_id' => $vmId])) {
                throw new NotFoundException('Disco não encontrado.');
            }
            $this->syncDisk($vmId);

            return $id;
        });
    }

    public function deleteDisk(int $id): void
    {
        $vm = $this->db->fetchOne('SELECT vm_id FROM virt_disk WHERE id = ?', [$id]);
        if (false === $vm) {
            throw new NotFoundException('Disco não encontrado.');
        }
        $this->db->executeStatement('DELETE FROM virt_disk WHERE id = ?', [$id]);
        $this->syncDisk((int) $vm);
    }

    private function syncDisk(int $vmId): void
    {
        $this->db->executeStatement('UPDATE virt_machine SET disk_gb = (SELECT CASE WHEN count(*) = 0 THEN virt_machine.disk_gb ELSE sum(size_gb) END FROM virt_disk WHERE vm_id = ?) WHERE id = ?', [$vmId, $vmId]);
    }

    /**
     * @return array{enabled: bool, mac_address: string|null, mtu: int|null, mode: string|null, untagged_vlan_id: int|null, bridge: string|null, description: string|null, tagged: list<int>}
     */
    private function ifaceFields(Payload $p, bool $bulk): array
    {
        $mac = $bulk ? null : PortRules::mac($p);
        $mode = $p->enum('mode', ['access', 'tagged', 'tagged-all']);
        $untagged = null === $mode ? null : $p->int('untagged_vlan_id', 1);
        $raw = $p->raw('tagged_vlan_ids');
        $tagged = 'tagged' === $mode && \is_array($raw) ? array_values(array_unique(array_map('intval', array_filter($raw, 'is_numeric')))) : [];
        if (null !== $untagged && false === $this->db->fetchOne('SELECT 1 FROM ipam_vlan WHERE id = ?', [$untagged])) {
            $p->error('untagged_vlan_id', 'VLAN não encontrada.');
        }
        if ([] !== $tagged && \count($tagged) !== (int) $this->db->fetchOne('SELECT count(*) FROM ipam_vlan WHERE id IN (?)', [$tagged], [ArrayParameterType::INTEGER])) {
            $p->error('tagged_vlan_ids', 'VLAN não encontrada.');
        }

        return ['enabled' => $p->bool('enabled', true), 'mac_address' => $mac, 'mtu' => $p->int('mtu', 68, 65536), 'mode' => $mode, 'untagged_vlan_id' => $untagged,
            'bridge' => $p->string('bridge', 64), 'description' => $p->string('description', 255), 'tagged' => $tagged];
    }

    /** @param list<int> $vlans */
    private function setTagged(int $ifaceId, array $vlans): void
    {
        $this->db->executeStatement('DELETE FROM virt_interface_vlan WHERE vm_interface_id = ?', [$ifaceId]);
        foreach ($vlans as $v) {
            $this->db->insert('virt_interface_vlan', ['vm_interface_id' => $ifaceId, 'vlan_id' => $v]);
        }
    }

    private function assertFreeIface(int $vmId, string $name, int $except = 0): void
    {
        if (false !== $this->db->fetchOne('SELECT 1 FROM virt_interface WHERE vm_id = ? AND name = ? AND id <> ?', [$vmId, $name, $except])) {
            throw new ValidationException(['name' => \sprintf('Já existe a interface "%s" nesta VM.', $name)]);
        }
    }
}
