<?php

declare(strict_types=1);

namespace App\Dcim\Service;

use App\Attachment\Service\AttachmentStorage;
use App\Ui\ReadModel\ManageQuery;
use App\Virtualization\Service\ClusterService;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Equipamentos e suas portas (ADR-0016 §2–3). Porta de `dcim.Device` + componentes
 * (netbox/dcim/models/devices.py, device_components.py):
 *  - na criação, instancia as portas e os mapeamentos frente↔traseira a partir dos modelos do tipo;
 *  - valida posição no rack (unidades livres por face; profundidade total ocupa as duas — Rack.get_available_units);
 *  - vínculo opcional e único com o ativo do inventário; IP primário precisa estar numa porta do próprio equipamento.
 */
final readonly class DeviceService
{
    public function __construct(
        private Connection $db,
        private ManageQuery $manage,
        private AttachmentStorage $attachments,
        private CableTracer $tracer,
    ) {
    }

    /**
     * @param array{q?: string|null, branch?: int|null, location?: int|null, rack?: int|null, role?: int|null, type?: int|null, status?: string|null} $f
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $f = [], int $limit = 1000): array
    {
        return Num::rows($this->db->fetchAllAssociative(<<<'SQL'
            SELECT d.id, d.name, d.status, d.serial, d.asset_tag, d.position::float8 AS position, d.face, d.branch_id, b.name AS branch, b.code AS branch_code,
                   d.location_id, l.name AS location, d.rack_id, k.name AS rack, ro.name AS role, ro.color, t.model AS device_type, m.name AS manufacturer,
                   t.u_height::float8 AS u_height, a.uuid AS asset_uuid, a.name AS asset_name,
                   (SELECT host(ip.address) FROM ipam_ip_address ip WHERE ip.id = d.primary_ip_id) AS primary_ip,
                   (SELECT count(*) FROM dcim_port p WHERE p.device_id = d.id) AS ports,
                   (SELECT count(*) FROM dcim_port p JOIN dcim_cable_termination ct ON ct.port_id = p.id WHERE p.device_id = d.id) AS connected,
                   (SELECT x.uuid FROM attachment x WHERE x.owner_type = 'device' AND x.owner_id = d.id AND x.kind = 'photo' ORDER BY x.created_at LIMIT 1) AS photo
            FROM dcim_device d
            JOIN branch b ON b.id = d.branch_id
            JOIN dcim_device_role ro ON ro.id = d.role_id
            JOIN dcim_device_type t ON t.id = d.device_type_id
            JOIN manufacturer m ON m.id = t.manufacturer_id
            LEFT JOIN location l ON l.id = d.location_id
            LEFT JOIN dcim_rack k ON k.id = d.rack_id
            LEFT JOIN asset a ON a.id = d.asset_id
            WHERE (CAST(:branch AS int) IS NULL OR d.branch_id = :branch)
              AND (CAST(:location AS int) IS NULL OR d.location_id IN (SELECT id FROM location WHERE path <@ (SELECT path FROM location WHERE id = :location)))
              AND (CAST(:rack AS int) IS NULL OR d.rack_id = :rack)
              AND (CAST(:role AS int) IS NULL OR d.role_id = :role)
              AND (CAST(:type AS int) IS NULL OR d.device_type_id = :type)
              AND (CAST(:status AS text) IS NULL OR d.status = :status)
              AND (CAST(:q AS text) IS NULL OR d.name ILIKE '%' || CAST(:q AS text) || '%' OR d.serial ILIKE '%' || CAST(:q AS text) || '%'
                   OR d.asset_tag ILIKE '%' || CAST(:q AS text) || '%' OR t.model ILIKE '%' || CAST(:q AS text) || '%' OR a.name ILIKE '%' || CAST(:q AS text) || '%')
            ORDER BY b.name, d.name
            LIMIT :limit
            SQL, ['branch' => $f['branch'] ?? null, 'location' => $f['location'] ?? null, 'rack' => $f['rack'] ?? null, 'role' => $f['role'] ?? null,
            'type' => $f['type'] ?? null, 'status' => $f['status'] ?? null, 'q' => $f['q'] ?? null, 'limit' => max(1, min($limit, 5000))]), ['position', 'u_height'], ['ports', 'connected']);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $d = $this->db->fetchAssociative(<<<'SQL'
            SELECT d.*, d.position::float8 AS position, b.name AS branch, l.name AS location, k.name AS rack, ro.name AS role, ro.color,
                   t.model AS device_type, t.u_height::float8 AS u_height, t.is_full_depth, m.name AS manufacturer,
                   a.uuid AS asset_uuid, a.name AS asset_name, a.inventory_number AS asset_inventory, a.serial_number AS asset_serial,
                   host(ip.address) || '/' || masklen(ip.address) AS primary_ip, vc.name AS cluster, vct.name AS cluster_type
            FROM dcim_device d
            JOIN branch b ON b.id = d.branch_id
            JOIN dcim_device_role ro ON ro.id = d.role_id
            JOIN dcim_device_type t ON t.id = d.device_type_id
            JOIN manufacturer m ON m.id = t.manufacturer_id
            LEFT JOIN location l ON l.id = d.location_id
            LEFT JOIN dcim_rack k ON k.id = d.rack_id
            LEFT JOIN asset a ON a.id = d.asset_id
            LEFT JOIN ipam_ip_address ip ON ip.id = d.primary_ip_id
            LEFT JOIN virt_cluster vc ON vc.id = d.cluster_id
            LEFT JOIN virt_cluster_type vct ON vct.id = vc.type_id
            WHERE d.id = ?
            SQL, [$id]) ?: throw new NotFoundException('Equipamento não encontrado.');
        $d = Num::row($d, ['position', 'u_height']);
        $d['ports'] = $this->ports($id);
        $d['ips'] = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT ip.id, host(ip.address) || '/' || masklen(ip.address) AS address, ip.status, ip.role, ip.dns_name, p.id AS port_id, p.name AS port
            FROM ipam_ip_address ip JOIN dcim_port p ON p.id = ip.port_id
            WHERE p.device_id = ? ORDER BY ip.address
            SQL, [$id]);
        $d['power'] = $this->powerSummary($id, $d['ports']);
        // virtualização (ADR-0017): capacidade do host e VMs que rodam nele
        $cap = ClusterService::HOST_CAPACITY;
        $d['capacity'] = Num::row($this->db->fetchAssociative("SELECT $cap FROM dcim_device d WHERE d.id = ?", [$id]) ?: [], [], ['cpu_threads', 'memory_mb', 'storage_gb']);
        $d['vms'] = Num::rows($this->db->fetchAllAssociative(<<<'SQL'
            SELECT v.id, v.name, v.status, v.platform, v.vcpus::float8 AS vcpus, v.memory_mb, v.disk_gb,
                   (SELECT host(ip.address) FROM ipam_ip_address ip WHERE ip.id = v.primary_ip_id) AS primary_ip
            FROM virt_machine v WHERE v.device_id = ? ORDER BY v.name
            SQL, [$id]), ['vcpus']);
        $d['attachments'] = $this->manage->attachments('device', $id);
        $d['type_photo'] = $this->db->fetchOne(
            "SELECT uuid FROM attachment WHERE owner_type = 'device_type' AND owner_id = ? AND kind = 'photo' ORDER BY created_at LIMIT 1", [$d['device_type_id']],
        ) ?: null;

        return $d;
    }

    /**
     * Portas com cabo, ponta oposta (link peer) e destino do caminho (connection) — a tabela de portas do NetBox.
     *
     * @return list<array<string, mixed>>
     */
    public function ports(int $deviceId): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT p.id, p.kind, p.name, p.label, p.type, p.positions, p.enabled, p.mgmt_only, p.mark_connected, p.mac_address, p.mode,
                   p.maximum_draw, p.allocated_draw, p.description, p.untagged_vlan_id, uv.vid AS untagged_vid, uv.name AS untagged_vlan,
                   (SELECT string_agg(v.vid::text, ',' ORDER BY v.vid) FROM dcim_port_vlan pv JOIN ipam_vlan v ON v.id = pv.vlan_id WHERE pv.port_id = p.id) AS tagged_vids,
                   (SELECT json_agg(v.id ORDER BY v.vid) FROM dcim_port_vlan pv JOIN ipam_vlan v ON v.id = pv.vlan_id WHERE pv.port_id = p.id) AS tagged_vlan_ids,
                   ct.cable_id, ct.cable_end, c.label AS cable_label, c.color AS cable_color, c.status AS cable_status, c.type AS cable_type,
                   (SELECT string_agg(host(ip.address) || '/' || masklen(ip.address), ', ' ORDER BY ip.address) FROM ipam_ip_address ip WHERE ip.port_id = p.id) AS ips,
                   m.rear_port_id, rp.name AS rear_port, m.rear_position
            FROM dcim_port p
            LEFT JOIN ipam_vlan uv ON uv.id = p.untagged_vlan_id
            LEFT JOIN dcim_cable_termination ct ON ct.port_id = p.id
            LEFT JOIN dcim_cable c ON c.id = ct.cable_id
            LEFT JOIN dcim_port_mapping m ON m.front_port_id = p.id
            LEFT JOIN dcim_port rp ON rp.id = m.rear_port_id
            WHERE p.device_id = ?
            SQL, [$deviceId]);
        $rows = PortRules::sort($rows);

        // pontas opostas de todos os cabos numa consulta só
        $cableIds = array_values(array_unique(array_filter(array_map(static fn (array $r): ?int => null === $r['cable_id'] ? null : (int) $r['cable_id'], $rows))));
        $peers = [];
        if ([] !== $cableIds) {
            foreach ($this->db->fetchAllAssociative(<<<'SQL'
                SELECT t.cable_id, t.cable_end, t.port_id, p.name AS port, p.kind, d.id AS device_id, d.name AS device,
                       t.circuit_termination_id, c.cid, s.name AS provider, ct.term_side
                FROM dcim_cable_termination t
                LEFT JOIN dcim_port p ON p.id = t.port_id LEFT JOIN dcim_device d ON d.id = p.device_id
                LEFT JOIN circuit_termination ct ON ct.id = t.circuit_termination_id LEFT JOIN circuit c ON c.id = ct.circuit_id
                LEFT JOIN supplier s ON s.id = c.supplier_id
                WHERE t.cable_id IN (?) ORDER BY t.id
                SQL, [$cableIds], [ArrayParameterType::INTEGER]) as $t) {
                $peers[(int) $t['cable_id']][(string) $t['cable_end']][] = $t;
            }
        }
        foreach ($rows as &$r) {
            $r['kind_label'] = DcimChoices::PORT_KINDS[(string) $r['kind']] ?? $r['kind'];
            $r['type_label'] = DcimChoices::PORT_TYPES[(string) $r['kind']][(string) $r['type']] ?? $r['type'];
            $r['tagged_vlan_ids'] = null === $r['tagged_vlan_ids'] ? [] : json_decode((string) $r['tagged_vlan_ids'], true);
            $r['link_peers'] = [];
            $r['destination'] = null;
            if (null === $r['cable_id']) {
                continue;
            }
            $far = 'A' === $r['cable_end'] ? 'B' : 'A';
            $r['link_peers'] = $peers[(int) $r['cable_id']][$far] ?? [];
            if (\in_array($r['kind'], DcimChoices::ENDPOINT_KINDS, true)) {
                $dest = $this->tracer->destination((int) $r['id']);
                $r['destination'] = null === $dest ? null : ['active' => $dest['active'], 'nodes' => array_map(static fn (array $n): array => [
                    'id' => $n['id'], 'name' => $n['name'], 'device_id' => $n['device_id'] ?? null, 'device' => $n['device'] ?? null,
                    'asset_uuid' => $n['asset_uuid'] ?? null, 'ips' => $n['ips'] ?? null,
                ], $dest['nodes'])];
            }
        }
        unset($r);

        return $rows;
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $name = $p->string('name', 100, required: true);
        $branchId = $p->int('branch_id', 1);
        $locationId = $p->int('location_id', 1);
        $rackId = $p->int('rack_id', 1);
        $typeId = $p->int('device_type_id', 1, required: null === $id);
        $roleId = $p->int('role_id', 1);
        $status = $p->enum('status', DcimChoices::keys('DEVICE_STATUS'), 'active');
        $face = $p->enum('face', ['front', 'rear']);
        $posRaw = $p->string('position', 6);
        $position = null;
        if (null !== $posRaw) {
            $n = str_replace(',', '.', $posRaw);
            if (!is_numeric($n) || (float) $n < 1 || fmod((float) $n * 2, 1.0) !== 0.0) {
                $p->error('position', 'Posição em U inválida (inteiro ou ,5).');
            } else {
                $position = (float) $n;
            }
        }
        $assetUuid = $p->string('asset_uuid', 36);
        $primaryIpId = $p->int('primary_ip_id', 1);
        $clusterId = $p->int('cluster_id', 1);
        if (null !== $clusterId && false === $this->db->fetchOne('SELECT 1 FROM virt_cluster WHERE id = ?', [$clusterId])) {
            $p->error('cluster_id', 'Cluster não encontrado.');
        }
        $current = null;
        if (null !== $id) {
            $current = $this->db->fetchAssociative('SELECT * FROM dcim_device WHERE id = ?', [$id]) ?: throw new NotFoundException('Equipamento não encontrado.');
            $typeId ??= (int) $current['device_type_id'];
        }

        $rack = null;
        if (null !== $rackId) {
            $rack = $this->db->fetchAssociative('SELECT id, branch_id, location_id, u_height, starting_unit FROM dcim_rack WHERE id = ?', [$rackId]);
            if (false === $rack) {
                $p->error('rack_id', 'Rack não encontrado.');
                $rack = null;
            } else {
                $branchId ??= (int) $rack['branch_id'];
                if ((int) $rack['branch_id'] !== $branchId) {
                    $p->error('rack_id', 'O rack é de outra filial.');
                }
                $locationId = null === $rack['location_id'] ? $locationId : (int) $rack['location_id']; // local do rack prevalece
            }
        }
        if (null === $branchId) {
            $p->error('branch_id', 'Informe a filial.');
        } elseif (false === $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ?', [$branchId])) {
            $p->error('branch_id', 'Filial não encontrada.');
        }
        if (null !== $locationId && false === $this->db->fetchOne('SELECT 1 FROM location WHERE id = ? AND branch_id IS NOT DISTINCT FROM ?', [$locationId, $branchId])) {
            $p->error('location_id', 'O local precisa ser da mesma filial.');
        }
        $type = null === $typeId ? false : $this->db->fetchAssociative('SELECT id, u_height::float8 AS u, is_full_depth, default_role_id FROM dcim_device_type WHERE id = ?', [$typeId]);
        if (null !== $typeId && false === $type) {
            $p->error('device_type_id', 'Tipo de equipamento não encontrado.');
        }
        $roleId ??= \is_array($type) && null !== $type['default_role_id'] ? (int) $type['default_role_id'] : null;
        if (null === $roleId) {
            $p->error('role_id', 'Informe a função.');
        } elseif (false === $this->db->fetchOne('SELECT 1 FROM dcim_device_role WHERE id = ?', [$roleId])) {
            $p->error('role_id', 'Função não encontrada.');
        }
        if (null !== $name && null !== $branchId && false !== $this->db->fetchOne('SELECT 1 FROM dcim_device WHERE branch_id = ? AND lower(name) = lower(?) AND id <> ?', [$branchId, $name, $id ?? 0])) {
            $p->error('name', 'Já existe um equipamento com esse nome nesta filial.');
        }
        $assetId = null;
        if (null !== $assetUuid) {
            $assetId = $this->db->fetchOne('SELECT id FROM asset WHERE uuid = CAST(? AS uuid) AND deleted_at IS NULL', [$assetUuid]);
            if (false === $assetId) {
                $p->error('asset_uuid', 'Ativo não encontrado.');
                $assetId = null;
            } else {
                $assetId = (int) $assetId;
                $other = $this->db->fetchOne('SELECT name FROM dcim_device WHERE asset_id = ? AND id <> ?', [$assetId, $id ?? 0]);
                if (false !== $other) {
                    $p->error('asset_uuid', \sprintf('O ativo já está vinculado ao equipamento "%s".', $other));
                }
            }
        }
        if (null !== $primaryIpId) {
            if (null === $id || false === $this->db->fetchOne('SELECT 1 FROM ipam_ip_address ip JOIN dcim_port p ON p.id = ip.port_id WHERE ip.id = ? AND p.device_id = ?', [$primaryIpId, $id])) {
                $p->error('primary_ip_id', 'O IP primário precisa estar numa porta deste equipamento.');
            }
        }
        // posição no rack
        if (null !== $position || null !== $face) {
            if (null === $rack) {
                $p->error('position', 'Escolha o rack para informar a posição.');
            } elseif (null === $position || null === $face) {
                $p->error(null === $position ? 'position' : 'face', 'Informe a posição (U) e a face.');
            } elseif (\is_array($type)) {
                $u = (float) $type['u'];
                if (0.0 === $u) {
                    $p->error('position', 'Equipamento de 0U não ocupa posição no rack (fica na lateral).');
                } else {
                    $top = $position + $u - 1;
                    $first = (int) $rack['starting_unit'];
                    $last = $first + (int) $rack['u_height'] - 1;
                    if ($position < $first || $top > $last) {
                        $p->error('position', \sprintf('Não cabe: o rack vai da U%d à U%d.', $first, $last));
                    } else {
                        $conflict = $this->conflicts((int) $rack['id'], $id, $position, $u, (string) $face, (bool) $type['is_full_depth']);
                        if (null !== $conflict) {
                            $p->error('position', 'Unidades ocupadas por '.$conflict.'.');
                        }
                    }
                }
            }
        }
        $p->check();

        $row = ['name' => $name, 'branch_id' => $branchId, 'location_id' => $locationId, 'rack_id' => $rackId, 'device_type_id' => $typeId, 'role_id' => $roleId,
            'status' => $status, 'position' => $position, 'face' => $face, 'serial' => $p->string('serial', 100), 'asset_tag' => $p->string('asset_tag', 100),
            'asset_id' => $assetId, 'primary_ip_id' => $primaryIpId, 'description' => $p->string('description', 255), 'comments' => $p->string('comments', 10000),
            'cluster_id' => $clusterId, 'cpu_threads' => $p->int('cpu_threads', 1, 100_000), 'memory_mb' => $p->int('memory_mb', 1, 1_000_000_000),
            'storage_gb' => $p->int('storage_gb', 1, 1_000_000_000)];
        $copyInterfaces = $p->bool('copy_interfaces');
        $p->check();

        return $this->db->transactional(function () use ($id, $row, $rackId, $copyInterfaces, $assetId): int {
            if (null !== $rackId) {
                $this->db->executeStatement('SELECT id FROM dcim_rack WHERE id = ? FOR UPDATE', [$rackId]); // serializa a ocupação do rack
            }
            if (null === $id) {
                $cols = implode(', ', array_keys($row));
                $marks = implode(', ', array_fill(0, \count($row) + 2, '?'));
                $newId = (int) $this->db->fetchOne("INSERT INTO dcim_device ($cols, created_at, updated_at) VALUES ($marks) RETURNING id", [...array_values($row), Refs::now(), Refs::now()]);
                $this->instantiate($newId, (int) $row['device_type_id']);
                if ($copyInterfaces && null !== $assetId) {
                    $this->copyAssetInterfaces($newId, $assetId);
                }

                return $newId;
            }
            $this->db->update('dcim_device', $row + ['updated_at' => Refs::now()], ['id' => $id]);
            // saiu do cluster: as VMs deixam de apontar para este host
            $this->db->executeStatement('UPDATE virt_machine SET device_id = NULL WHERE device_id = ? AND cluster_id IS DISTINCT FROM ?', [$id, $row['cluster_id']]);

            return $id;
        });
    }

    /** Remove o equipamento, as portas e os cabos ligados a elas (sem deixar cabo com uma ponta só). */
    public function delete(int $id): void
    {
        if (false === $this->db->fetchOne('SELECT 1 FROM dcim_device WHERE id = ?', [$id])) {
            throw new NotFoundException('Equipamento não encontrado.');
        }
        $this->db->transactional(function () use ($id): void {
            $this->db->executeStatement('DELETE FROM dcim_cable WHERE id IN (SELECT t.cable_id FROM dcim_cable_termination t JOIN dcim_port p ON p.id = t.port_id WHERE p.device_id = ?)', [$id]);
            $this->db->executeStatement('DELETE FROM dcim_device WHERE id = ?', [$id]);
        });
        $this->attachments->deleteAllOf('device', $id);
    }

    // ------------------------------------------------------------------ portas

    /**
     * Portas em lote (padrão de nome) ou par frontal/traseira ("pair"). Frontal avulsa pode mapear
     * numa traseira existente a partir de `rear_position`.
     *
     * @param array<string, mixed> $data
     *
     * @return list<int>
     */
    public function addPorts(int $deviceId, array $data): array
    {
        if (false === $this->db->fetchOne('SELECT 1 FROM dcim_device WHERE id = ?', [$deviceId])) {
            throw new NotFoundException('Equipamento não encontrado.');
        }
        $p = new Payload($data);
        $kind = $p->enum('kind', [...array_keys(DcimChoices::PORT_KINDS), 'pair']);
        $pattern = $p->string('name', 120, required: true);
        $p->check();
        $names = NamePattern::expand((string) $pattern);
        $base = 'pair' === $kind ? 'front' : (string) $kind;
        $f = PortRules::read($p, $base);
        $mac = PortRules::mac($p);
        if (null !== $mac && \count($names) > 1) {
            $p->error('mac_address', 'MAC só na criação de uma porta por vez.');
        }
        $rearType = 'pair' === $kind ? ($p->string('rear_type', 32) ?? '110-punch') : null;
        $rear = null;
        $startPos = 1;
        if ('front' === $kind) {
            $rearId = $p->int('rear_port_id', 1, required: true);
            $startPos = $p->int('rear_position', 1, 1024) ?? 1;
            $rear = null === $rearId ? false : $this->db->fetchAssociative("SELECT id, positions FROM dcim_port WHERE id = ? AND device_id = ? AND kind = 'rear'", [$rearId, $deviceId]);
            if (null !== $rearId && false === $rear) {
                $p->error('rear_port_id', 'Porta traseira não encontrada neste equipamento.');
            }
            if (\is_array($rear) && $startPos + \count($names) - 1 > (int) $rear['positions']) {
                $p->error('rear_position', \sprintf('A porta traseira tem %d posição(ões).', $rear['positions']));
            }
        }
        $p->check();

        return $this->db->transactional(function () use ($deviceId, $kind, $base, $names, $f, $mac, $rearType, $rear, $startPos): array {
            $ids = [];
            foreach ($names as $i => $name) {
                $this->assertFreePortName($deviceId, $base, $name);
                if ('pair' === $kind) {
                    $this->assertFreePortName($deviceId, 'rear', $name);
                    $rid = $this->insertPort($deviceId, 'rear', $name, ['type' => $rearType, 'positions' => 1] + $f, null);
                    $fid = $this->insertPort($deviceId, 'front', $name, $f, null);
                    $this->db->insert('dcim_port_mapping', ['front_port_id' => $fid, 'front_position' => 1, 'rear_port_id' => $rid, 'rear_position' => 1]);
                    $ids[] = $fid;
                    continue;
                }
                $pid = $this->insertPort($deviceId, $base, $name, $f, $mac);
                if (\is_array($rear)) {
                    $this->map($pid, (int) $rear['id'], $startPos + $i);
                }
                $ids[] = $pid;
            }

            return $ids;
        });
    }

    /** @param array<string, mixed> $data */
    public function updatePort(int $id, array $data): void
    {
        $port = $this->db->fetchAssociative('SELECT id, device_id, kind FROM dcim_port WHERE id = ?', [$id]) ?: throw new NotFoundException('Porta não encontrada.');
        $kind = (string) $port['kind'];
        $p = new Payload($data);
        $name = $p->string('name', 64, required: true);
        $f = PortRules::read($p, $kind);
        $mac = 'interface' === $kind ? PortRules::mac($p) : null;
        $mode = 'interface' === $kind ? $p->enum('mode', ['access', 'tagged', 'tagged-all']) : null;
        $untagged = 'interface' === $kind ? $p->int('untagged_vlan_id', 1) : null;
        $taggedRaw = $p->raw('tagged_vlan_ids');
        $tagged = \is_array($taggedRaw) ? array_values(array_unique(array_map('intval', array_filter($taggedRaw, 'is_numeric')))) : [];
        if ('tagged' !== $mode) {
            $tagged = [];
        }
        if (null === $mode) {
            $untagged = null;
        }
        if (null !== $untagged && false === $this->db->fetchOne('SELECT 1 FROM ipam_vlan WHERE id = ?', [$untagged])) {
            $p->error('untagged_vlan_id', 'VLAN não encontrada.');
        }
        if ([] !== $tagged && \count($tagged) !== (int) $this->db->fetchOne('SELECT count(*) FROM ipam_vlan WHERE id IN (?)', [$tagged], [ArrayParameterType::INTEGER])) {
            $p->error('tagged_vlan_ids', 'VLAN tagged não encontrada.');
        }
        if ('rear' === $kind) {
            $mapped = (int) $this->db->fetchOne('SELECT coalesce(max(rear_position), 0) FROM dcim_port_mapping WHERE rear_port_id = ?', [$id]);
            if ($f['positions'] < $mapped) {
                $p->error('positions', \sprintf('Há porta frontal mapeada na posição %d.', $mapped));
            }
        }
        $enabled = $p->bool('enabled', true);
        $markConnected = $p->bool('mark_connected');
        if ($markConnected && false !== $this->db->fetchOne('SELECT 1 FROM dcim_cable_termination WHERE port_id = ?', [$id])) {
            $p->error('mark_connected', 'A porta já tem cabo.');
        }
        $p->check();
        $this->assertFreePortName((int) $port['device_id'], $kind, (string) $name, $id);
        $this->db->transactional(function () use ($id, $name, $f, $mac, $mode, $untagged, $tagged, $enabled, $markConnected, $kind, $data): void {
            $this->db->update('dcim_port', ['name' => $name, 'label' => $f['label'], 'type' => $f['type'], 'positions' => $f['positions'],
                'mgmt_only' => $f['mgmt_only'] ? 'true' : 'false', 'maximum_draw' => $f['maximum_draw'], 'allocated_draw' => $f['allocated_draw'],
                'description' => $f['description'], 'mac_address' => $mac, 'mode' => $mode, 'untagged_vlan_id' => $untagged,
                'enabled' => $enabled ? 'true' : 'false', 'mark_connected' => $markConnected ? 'true' : 'false', 'updated_at' => Refs::now()], ['id' => $id]);
            $this->db->executeStatement('DELETE FROM dcim_port_vlan WHERE port_id = ?', [$id]);
            foreach ($tagged as $vlan) {
                $this->db->insert('dcim_port_vlan', ['port_id' => $id, 'vlan_id' => $vlan]);
            }
            if ('front' === $kind && \array_key_exists('rear_port_id', $data)) {
                $p = new Payload($data);
                $rearId = $p->int('rear_port_id', 1);
                $pos = $p->int('rear_position', 1, 1024) ?? 1;
                $this->db->executeStatement('DELETE FROM dcim_port_mapping WHERE front_port_id = ?', [$id]);
                if (null !== $rearId) {
                    $this->map($id, $rearId, $pos);
                }
            }
        });
    }

    /** Exclui portas (e os cabos ligados a elas). @param list<int> $ids */
    public function deletePorts(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->db->transactional(function () use ($ids): int {
            $this->db->executeStatement('DELETE FROM dcim_cable WHERE id IN (SELECT cable_id FROM dcim_cable_termination WHERE port_id IN (?))', [$ids], [ArrayParameterType::INTEGER]);

            return $this->db->executeStatement('DELETE FROM dcim_port WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        });
    }

    /**
     * Portas de um equipamento para os seletores (conectar cabo, atribuir IP).
     *
     * @return list<array<string, mixed>>
     */
    public function portOptions(int $deviceId, ?string $kind = null, bool $freeOnly = false): array
    {
        return PortRules::sort($this->db->fetchAllAssociative(<<<'SQL'
            SELECT p.id, p.kind, p.name, p.label, p.type, p.positions, p.mark_connected, t.cable_id,
                   (SELECT count(*) FROM dcim_port_mapping m WHERE m.rear_port_id = p.id) AS mapped_positions
            FROM dcim_port p LEFT JOIN dcim_cable_termination t ON t.port_id = p.id
            WHERE p.device_id = :device AND (CAST(:kind AS text) IS NULL OR p.kind = :kind)
              AND (NOT CAST(:free AS boolean) OR (t.id IS NULL AND NOT p.mark_connected))
            SQL, ['device' => $deviceId, 'kind' => $kind, 'free' => $freeOnly ? 'true' : 'false']));
    }

    /**
     * Ativos do inventário ainda sem equipamento, para vincular.
     *
     * @return list<array<string, mixed>>
     */
    public function assetCandidates(?string $q, ?int $branchId): array
    {
        return $this->db->fetchAllAssociative(<<<'SQL'
            SELECT a.uuid, a.name, a.serial_number, a.inventory_number, t.label AS type, b.name AS branch, a.branch_id, a.location_id,
                   (SELECT count(*) FROM network_port np WHERE np.asset_id = a.id AND NOT np.virtual) AS interfaces
            FROM asset a JOIN asset_type t ON t.id = a.asset_type_id LEFT JOIN branch b ON b.id = a.branch_id
            WHERE a.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM dcim_device d WHERE d.asset_id = a.id)
              AND (CAST(:branch AS int) IS NULL OR a.branch_id = :branch)
              AND (CAST(:q AS text) IS NULL OR a.name ILIKE '%' || CAST(:q AS text) || '%' OR a.serial_number ILIKE '%' || CAST(:q AS text) || '%'
                   OR a.inventory_number ILIKE '%' || CAST(:q AS text) || '%')
            ORDER BY a.name LIMIT 30
            SQL, ['q' => $q, 'branch' => $branchId]);
    }

    /**
     * Equipamento físico do ativo (tela do ativo): onde está e onde cada interface chega.
     *
     * @return array<string, mixed>|null
     */
    public function forAsset(int $assetId): ?array
    {
        $id = $this->db->fetchOne('SELECT id FROM dcim_device WHERE asset_id = ?', [$assetId]);
        if (false === $id) {
            return null;
        }
        $d = $this->db->fetchAssociative(<<<'SQL'
            SELECT d.id, d.name, d.status, b.name AS branch, l.name AS location, k.name AS rack, d.position::float8 AS position, d.face, ro.name AS role
            FROM dcim_device d JOIN branch b ON b.id = d.branch_id JOIN dcim_device_role ro ON ro.id = d.role_id
            LEFT JOIN location l ON l.id = d.location_id LEFT JOIN dcim_rack k ON k.id = d.rack_id
            WHERE d.id = ?
            SQL, [$id]) ?: null;
        if (null === $d) {
            return null;
        }
        $d = Num::row($d, ['position']);
        $d['connections'] = array_values(array_map(static fn (array $p): array => [
            'port_id' => $p['id'], 'port' => $p['name'], 'kind' => $p['kind'], 'cable_label' => $p['cable_label'], 'destination' => $p['destination'],
            'link_peers' => $p['link_peers'],
        ], array_filter($this->ports((int) $id), static fn (array $p): bool => null !== $p['cable_id'])));

        return $d;
    }

    // ------------------------------------------------------------------ internos

    /** Instancia portas e mapeamentos a partir dos modelos do tipo (component templates). */
    private function instantiate(int $deviceId, int $typeId): void
    {
        $now = Refs::now();
        $this->db->executeStatement(<<<'SQL'
            INSERT INTO dcim_port (device_id, kind, name, label, type, positions, mgmt_only, maximum_draw, allocated_draw, description, created_at, updated_at)
            SELECT ?, kind, name, label, type, positions, mgmt_only, maximum_draw, allocated_draw, description, ?, ?
            FROM dcim_port_template WHERE device_type_id = ?
            SQL, [$deviceId, $now, $now, $typeId]);
        $this->db->executeStatement(<<<'SQL'
            INSERT INTO dcim_port_mapping (front_port_id, front_position, rear_port_id, rear_position)
            SELECT fp.id, 1, rp.id, ft.rear_position
            FROM dcim_port_template ft
            JOIN dcim_port_template rt ON rt.id = ft.rear_port_template_id
            JOIN dcim_port fp ON fp.device_id = :device AND fp.kind = 'front' AND fp.name = ft.name
            JOIN dcim_port rp ON rp.device_id = :device AND rp.kind = 'rear' AND rp.name = rt.name
            WHERE ft.device_type_id = :type AND ft.kind = 'front'
            SQL, ['device' => $deviceId, 'type' => $typeId]);
    }

    /** Copia as NICs físicas do inventário do agente como interfaces (nome + MAC). */
    private function copyAssetInterfaces(int $deviceId, int $assetId): void
    {
        $now = Refs::now();
        $this->db->executeStatement(<<<'SQL'
            INSERT INTO dcim_port (device_id, kind, name, type, mac_address, created_at, updated_at)
            SELECT ?, 'interface', left(np.name, 64), CASE WHEN np.type ILIKE '%wifi%' OR np.type ILIKE '%wireless%' THEN 'ieee802.11ac' ELSE '1000base-t' END,
                   np.mac_address, ?, ?
            FROM network_port np
            WHERE np.asset_id = ? AND NOT np.virtual
              AND NOT EXISTS (SELECT 1 FROM dcim_port p WHERE p.device_id = ? AND p.kind = 'interface' AND p.name = left(np.name, 64))
            ON CONFLICT DO NOTHING
            SQL, [$deviceId, $now, $now, $assetId, $deviceId]);
    }

    private function map(int $frontId, int $rearId, int $position): void
    {
        $rear = $this->db->fetchAssociative(
            "SELECT r.positions FROM dcim_port r JOIN dcim_port f ON f.device_id = r.device_id WHERE r.id = ? AND r.kind = 'rear' AND f.id = ?", [$rearId, $frontId],
        );
        if (false === $rear) {
            throw new ValidationException(['rear_port_id' => 'A porta traseira precisa ser do mesmo equipamento.']);
        }
        if ($position > (int) $rear['positions']) {
            throw new ValidationException(['rear_position' => \sprintf('A porta traseira tem %d posição(ões).', $rear['positions'])]);
        }
        if (false !== $this->db->fetchOne('SELECT 1 FROM dcim_port_mapping WHERE rear_port_id = ? AND rear_position = ?', [$rearId, $position])) {
            throw new ValidationException(['rear_position' => \sprintf('A posição %d da porta traseira já está mapeada.', $position)]);
        }
        $this->db->insert('dcim_port_mapping', ['front_port_id' => $frontId, 'front_position' => 1, 'rear_port_id' => $rearId, 'rear_position' => $position]);
    }

    /** @param array<string, mixed> $f */
    private function insertPort(int $deviceId, string $kind, string $name, array $f, ?string $mac): int
    {
        return (int) $this->db->fetchOne(
            'INSERT INTO dcim_port (device_id, kind, name, label, type, positions, mgmt_only, maximum_draw, allocated_draw, description, mac_address, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$deviceId, $kind, $name, $f['label'], $f['type'], 'rear' === $kind ? $f['positions'] : 1, ($f['mgmt_only'] ?? false) ? 'true' : 'false',
                'power_port' === $kind ? $f['maximum_draw'] : null, 'power_port' === $kind ? $f['allocated_draw'] : null, $f['description'],
                'interface' === $kind ? $mac : null, Refs::now(), Refs::now()],
        );
    }

    private function assertFreePortName(int $deviceId, string $kind, string $name, int $except = 0): void
    {
        if (false !== $this->db->fetchOne('SELECT 1 FROM dcim_port WHERE device_id = ? AND kind = ? AND name = ? AND id <> ?', [$deviceId, $kind, $name, $except])) {
            throw new ValidationException(['name' => \sprintf('Já existe a porta "%s" (%s) neste equipamento.', $name, DcimChoices::PORT_KINDS[$kind] ?? $kind)]);
        }
    }

    /** Equipamento que já ocupa alguma das unidades na mesma face (ou em qualquer face, se um dos dois for de profundidade total). */
    private function conflicts(int $rackId, ?int $exceptId, float $position, float $u, string $face, bool $fullDepth): ?string
    {
        $v = $this->db->fetchOne(<<<'SQL'
            SELECT string_agg(d.name || ' (U' || trim_scale(d.position) || ')', ', ')
            FROM dcim_device d JOIN dcim_device_type t ON t.id = d.device_type_id
            WHERE d.rack_id = :rack AND d.id <> :except AND d.position IS NOT NULL AND t.u_height > 0
              AND d.position < CAST(:pos AS numeric) + CAST(:u AS numeric) AND CAST(:pos AS numeric) < d.position + t.u_height
              AND (d.face = :face OR t.is_full_depth OR CAST(:full AS boolean))
            SQL, ['rack' => $rackId, 'except' => $exceptId ?? 0, 'pos' => $position, 'u' => $u, 'face' => $face, 'full' => $fullDepth ? 'true' : 'false']);

        return \is_string($v) && '' !== $v ? $v : null;
    }

    /**
     * Energia: soma do consumo alocado nos equipamentos ligados às tomadas desta PDU/quadro, contra o máximo
     * da entrada (PowerPort.get_power_draw do NetBox, simplificado: sem fases).
     *
     * @param list<array<string, mixed>> $ports
     *
     * @return array<string, mixed>|null
     */
    private function powerSummary(int $deviceId, array $ports): ?array
    {
        $outlets = array_values(array_filter($ports, static fn (array $p): bool => 'power_outlet' === $p['kind']));
        if ([] === $outlets) {
            return null;
        }
        $row = $this->db->fetchAssociative(<<<'SQL'
            SELECT count(DISTINCT o.id) FILTER (WHERE far.id IS NOT NULL) AS used_outlets,
                   coalesce(sum(coalesce(fp.allocated_draw, fp.maximum_draw)), 0) AS allocated
            FROM dcim_port o
            LEFT JOIN dcim_cable_termination t ON t.port_id = o.id
            LEFT JOIN dcim_cable_termination far ON far.cable_id = t.cable_id AND far.cable_end <> t.cable_end
            LEFT JOIN dcim_port fp ON fp.id = far.port_id AND fp.kind = 'power_port'
            WHERE o.device_id = ? AND o.kind = 'power_outlet'
            SQL, [$deviceId]) ?: [];
        $max = $this->db->fetchOne("SELECT sum(maximum_draw) FROM dcim_port WHERE device_id = ? AND kind = 'power_port'", [$deviceId]);

        return ['outlets' => \count($outlets), 'used_outlets' => (int) ($row['used_outlets'] ?? 0), 'allocated_w' => (int) ($row['allocated'] ?? 0),
            'maximum_w' => null === $max || false === $max ? null : (int) $max];
    }
}
