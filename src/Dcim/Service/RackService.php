<?php

declare(strict_types=1);

namespace App\Dcim\Service;

use App\Attachment\Service\AttachmentStorage;
use App\Ui\ReadModel\ManageQuery;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;

/** Racks e elevação (ADR-0016 §2). Porta de `dcim.Rack` (netbox/dcim/models/racks.py). */
final readonly class RackService
{
    public function __construct(
        private Connection $db,
        private ManageQuery $manage,
        private AttachmentStorage $attachments,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(?int $branchId = null, ?int $locationId = null, ?string $q = null): array
    {
        return Num::rows($this->db->fetchAllAssociative(<<<'SQL'
            SELECT r.id, r.name, r.status, r.role, r.u_height, r.width, r.facility_id, r.branch_id, b.name AS branch, b.code AS branch_code,
                   r.location_id, l.name AS location,
                   (SELECT count(*) FROM dcim_device d WHERE d.rack_id = r.id) AS devices,
                   (SELECT coalesce(sum(t.u_height), 0)::float8 FROM dcim_device d JOIN dcim_device_type t ON t.id = d.device_type_id
                     WHERE d.rack_id = r.id AND d.position IS NOT NULL) AS used_u,
                   (SELECT a.uuid FROM attachment a WHERE a.owner_type = 'rack' AND a.owner_id = r.id AND a.kind = 'photo' ORDER BY a.created_at LIMIT 1) AS photo
            FROM dcim_rack r
            JOIN branch b ON b.id = r.branch_id
            LEFT JOIN location l ON l.id = r.location_id
            WHERE (CAST(:branch AS int) IS NULL OR r.branch_id = :branch)
              AND (CAST(:location AS int) IS NULL OR r.location_id IN (SELECT id FROM location WHERE path <@ (SELECT path FROM location WHERE id = :location)))
              AND (CAST(:q AS text) IS NULL OR r.name ILIKE '%' || CAST(:q AS text) || '%' OR r.facility_id ILIKE '%' || CAST(:q AS text) || '%')
            ORDER BY b.name, r.name
            SQL, ['branch' => $branchId, 'location' => $locationId, 'q' => $q]), ['used_u'], ['u_height', 'width', 'devices']);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $r = $this->db->fetchAssociative(<<<'SQL'
            SELECT r.*, b.name AS branch, l.name AS location
            FROM dcim_rack r JOIN branch b ON b.id = r.branch_id LEFT JOIN location l ON l.id = r.location_id
            WHERE r.id = ?
            SQL, [$id]) ?: throw new NotFoundException('Rack não encontrado.');
        $r = Num::row($r, [], ['u_height', 'width', 'starting_unit', 'outer_depth_mm']);
        $devices = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT d.id, d.name, d.status, d.position::float8 AS position, d.face, d.serial, d.asset_tag,
                   t.model AS device_type, t.u_height::float8 AS u_height, t.is_full_depth, m.name AS manufacturer,
                   ro.name AS role, ro.color, a.uuid AS asset_uuid,
                   (SELECT host(ip.address) FROM ipam_ip_address ip WHERE ip.id = d.primary_ip_id) AS primary_ip,
                   (SELECT x.uuid FROM attachment x WHERE x.owner_type = 'device' AND x.owner_id = d.id AND x.kind = 'photo' ORDER BY x.created_at LIMIT 1) AS photo,
                   (SELECT x.uuid FROM attachment x WHERE x.owner_type = 'device_type' AND x.owner_id = t.id AND x.kind = 'photo' ORDER BY x.created_at LIMIT 1) AS type_photo
            FROM dcim_device d
            JOIN dcim_device_type t ON t.id = d.device_type_id
            JOIN manufacturer m ON m.id = t.manufacturer_id
            JOIN dcim_device_role ro ON ro.id = d.role_id
            LEFT JOIN asset a ON a.id = d.asset_id
            WHERE d.rack_id = ?
            ORDER BY d.position DESC NULLS LAST, d.name
            SQL, [$id]);
        $devices = Num::rows($devices, ['position', 'u_height']);
        $r['devices'] = array_values(array_filter($devices, static fn (array $d): bool => null !== $d['position']));
        $r['unracked'] = array_values(array_filter($devices, static fn (array $d): bool => null === $d['position']));
        $used = 0.0;
        foreach ($r['devices'] as $d) {
            $used += (float) $d['u_height'];
        }
        $r['used_u'] = $used;
        $r['utilization'] = (int) $r['u_height'] > 0 ? round(100 * $used / (int) $r['u_height'], 1) : 0;
        $r['attachments'] = $this->manage->attachments('rack', $id);

        return $r;
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $branchId = $p->int('branch_id', 1, required: true);
        $locationId = $p->int('location_id', 1);
        $name = $p->string('name', 100, required: true);
        $status = $p->enum('status', DcimChoices::keys('RACK_STATUS'), 'active');
        $uHeight = $p->int('u_height', 1, 100) ?? 42;
        $width = $p->int('width', 10, 23) ?? 19;
        if (!\in_array($width, DcimChoices::RACK_WIDTHS, true)) {
            $p->error('width', 'Largura: 10, 19, 21 ou 23 polegadas.');
        }
        $startingUnit = $p->int('starting_unit', 1, 100) ?? 1;
        if (null !== $branchId && false === $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ?', [$branchId])) {
            $p->error('branch_id', 'Filial não encontrada.');
        }
        if (null !== $locationId && false === $this->db->fetchOne('SELECT 1 FROM location WHERE id = ? AND branch_id = ?', [$locationId, $branchId])) {
            $p->error('location_id', 'O local precisa ser da mesma filial.');
        }
        if (null !== $name && false !== $this->db->fetchOne('SELECT 1 FROM dcim_rack WHERE branch_id = ? AND lower(name) = lower(?) AND id <> ?', [$branchId, $name, $id ?? 0])) {
            $p->error('name', 'Já existe um rack com esse nome nesta filial.');
        }
        if (null !== $id) {
            $cur = $this->db->fetchAssociative('SELECT branch_id FROM dcim_rack WHERE id = ?', [$id]) ?: throw new NotFoundException('Rack não encontrado.');
            $hasDevices = false !== $this->db->fetchOne('SELECT 1 FROM dcim_device WHERE rack_id = ? LIMIT 1', [$id]);
            if ($hasDevices && (int) $cur['branch_id'] !== $branchId) {
                $p->error('branch_id', 'O rack tem equipamentos; retire-os antes de trocar a filial.');
            }
            // os equipamentos precisam continuar cabendo (Rack.clean() do NetBox)
            $out = $this->db->fetchOne(<<<'SQL'
                SELECT string_agg(d.name, ', ') FROM dcim_device d JOIN dcim_device_type t ON t.id = d.device_type_id
                WHERE d.rack_id = ? AND d.position IS NOT NULL AND (d.position < ? OR d.position + t.u_height - 1 > ?)
                SQL, [$id, $startingUnit, $startingUnit + $uHeight - 1]);
            if (\is_string($out) && '' !== $out) {
                $p->error('u_height', 'Equipamentos ficariam fora do rack: '.$out.'.');
            }
        }
        $p->check();

        $row = ['branch_id' => $branchId, 'location_id' => $locationId, 'name' => $name, 'status' => $status, 'role' => $p->string('role', 64),
            'facility_id' => $p->string('facility_id', 50), 'serial' => $p->string('serial', 100), 'asset_tag' => $p->string('asset_tag', 100),
            'u_height' => $uHeight, 'width' => $width, 'starting_unit' => $startingUnit, 'desc_units' => $p->bool('desc_units') ? 'true' : 'false',
            'outer_depth_mm' => $p->int('outer_depth_mm', 1, 3000), 'description' => $p->string('description', 255), 'comments' => $p->string('comments', 10000)];
        $p->check();
        if (null === $id) {
            $cols = implode(', ', array_keys($row));
            $marks = implode(', ', array_fill(0, \count($row) + 2, '?'));

            return (int) $this->db->fetchOne("INSERT INTO dcim_rack ($cols, created_at, updated_at) VALUES ($marks) RETURNING id", [...array_values($row), Refs::now(), Refs::now()]);
        }
        $this->db->update('dcim_rack', $row + ['updated_at' => Refs::now()], ['id' => $id]);
        // a localização dos equipamentos acompanha o rack
        $this->db->executeStatement('UPDATE dcim_device SET location_id = ? WHERE rack_id = ? AND location_id IS DISTINCT FROM ?', [$locationId, $id, $locationId]);

        return $id;
    }

    public function delete(int $id): void
    {
        $n = (int) $this->db->fetchOne('SELECT count(*) FROM dcim_device WHERE rack_id = ?', [$id]);
        if ($n > 0) {
            throw new ValidationException(['id' => \sprintf('O rack ainda tem %d equipamento(s).', $n)]);
        }
        $this->attachments->deleteAllOf('rack', $id);
        if (0 === $this->db->executeStatement('DELETE FROM dcim_rack WHERE id = ?', [$id])) {
            throw new NotFoundException('Rack não encontrado.');
        }
    }
}
