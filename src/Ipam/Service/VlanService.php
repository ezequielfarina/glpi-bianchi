<?php

declare(strict_types=1);

namespace App\Ipam\Service;

use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;

/**
 * Grupos de VLAN e VLANs (ADR-0016 §7; VRF removida no ADR-0021). Porta de `ipam.VLANGroup` e `ipam.VLAN`
 * (netbox/ipam/models/vlans.py): VID único no grupo e dentro da faixa do grupo.
 */
final readonly class VlanService
{
    public const array VLAN_STATUS = ['active' => 'Ativa', 'reserved' => 'Reservada', 'deprecated' => 'Obsoleta'];

    public function __construct(private Connection $db)
    {
    }

    // ------------------------------------------------------------------ grupos

    /** @return list<array<string, mixed>> */
    public function groups(): array
    {
        return $this->db->fetchAllAssociative(<<<'SQL'
            SELECT g.id, g.name, g.branch_id, b.name AS branch, g.min_vid, g.max_vid, g.description,
                   (SELECT count(*) FROM ipam_vlan v WHERE v.group_id = g.id) AS vlans
            FROM ipam_vlan_group g LEFT JOIN branch b ON b.id = g.branch_id ORDER BY g.name
            SQL);
    }

    /** @param array<string, mixed> $data */
    public function saveGroup(?int $id, array $data): int
    {
        $p = new Payload($data);
        $name = $p->string('name', 100, required: true);
        $branchId = $p->int('branch_id', 1);
        $min = $p->int('min_vid', 1, 4094) ?? 1;
        $max = $p->int('max_vid', 1, 4094) ?? 4094;
        if ($min > $max) {
            $p->error('max_vid', 'O VID final precisa ser maior que o inicial.');
        }
        if (null !== $branchId && false === $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ?', [$branchId])) {
            $p->error('branch_id', 'Filial não encontrada.');
        }
        if (null !== $name && false !== $this->db->fetchOne('SELECT 1 FROM ipam_vlan_group WHERE lower(name) = lower(?) AND id <> ?', [$name, $id ?? 0])) {
            $p->error('name', 'Já existe um grupo com esse nome.');
        }
        if (null !== $id) {
            $out = $this->db->fetchOne('SELECT string_agg(vid::text, \', \') FROM ipam_vlan WHERE group_id = ? AND (vid < ? OR vid > ?)', [$id, $min, $max]);
            if (\is_string($out) && '' !== $out) {
                $p->error('min_vid', 'VLANs ficariam fora da faixa: '.$out.'.');
            }
        }
        $p->check();
        $row = ['name' => $name, 'branch_id' => $branchId, 'min_vid' => $min, 'max_vid' => $max, 'description' => $p->string('description', 255)];
        if (null === $id) {
            return (int) $this->db->fetchOne('INSERT INTO ipam_vlan_group (name, branch_id, min_vid, max_vid, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [...array_values($row), Refs::now(), Refs::now()]);
        }
        if (0 === $this->db->update('ipam_vlan_group', $row + ['updated_at' => Refs::now()], ['id' => $id])) {
            throw new NotFoundException('Grupo de VLANs não encontrado.');
        }

        return $id;
    }

    public function deleteGroup(int $id): void
    {
        $n = (int) $this->db->fetchOne('SELECT count(*) FROM ipam_vlan WHERE group_id = ?', [$id]);
        if ($n > 0) {
            throw new ValidationException(['id' => \sprintf('O grupo ainda tem %d VLAN(s).', $n)]);
        }
        if (0 === $this->db->executeStatement('DELETE FROM ipam_vlan_group WHERE id = ?', [$id])) {
            throw new NotFoundException('Grupo de VLANs não encontrado.');
        }
    }

    // ------------------------------------------------------------------ VLANs

    /**
     * @param array{q?: string|null, group?: int|null, branch?: int|null, status?: string|null} $f
     *
     * @return list<array<string, mixed>>
     */
    public function vlans(array $f = []): array
    {
        return $this->db->fetchAllAssociative(<<<'SQL'
            SELECT v.id, v.vid, v.name, v.status, v.role, v.description, v.group_id, g.name AS "group", v.branch_id, b.name AS branch,
                   (SELECT string_agg(p.prefix::text, ', ' ORDER BY p.prefix) FROM ipam_prefix p WHERE p.vlan_id = v.id) AS prefixes,
                   (SELECT count(*) FROM dcim_port p WHERE p.untagged_vlan_id = v.id) + (SELECT count(*) FROM dcim_port_vlan pv WHERE pv.vlan_id = v.id) AS ports
            FROM ipam_vlan v LEFT JOIN ipam_vlan_group g ON g.id = v.group_id LEFT JOIN branch b ON b.id = v.branch_id
            WHERE (CAST(:grp AS int) IS NULL OR v.group_id = :grp)
              AND (CAST(:branch AS int) IS NULL OR v.branch_id = :branch OR g.branch_id = :branch)
              AND (CAST(:status AS text) IS NULL OR v.status = :status)
              AND (CAST(:q AS text) IS NULL OR v.name ILIKE '%' || CAST(:q AS text) || '%' OR v.vid::text = CAST(:q AS text))
            ORDER BY g.name NULLS FIRST, v.vid
            SQL, ['grp' => $f['group'] ?? null, 'branch' => $f['branch'] ?? null, 'status' => $f['status'] ?? null, 'q' => $f['q'] ?? null]);
    }

    /** @return array<string, mixed> */
    public function vlan(int $id): array
    {
        $v = $this->db->fetchAssociative(<<<'SQL'
            SELECT v.*, g.name AS "group", b.name AS branch FROM ipam_vlan v LEFT JOIN ipam_vlan_group g ON g.id = v.group_id LEFT JOIN branch b ON b.id = v.branch_id WHERE v.id = ?
            SQL, [$id]) ?: throw new NotFoundException('VLAN não encontrada.');
        $v['prefixes'] = $this->db->fetchAllAssociative('SELECT id, prefix::text AS prefix, status, description FROM ipam_prefix WHERE vlan_id = ? ORDER BY prefix', [$id]);
        $v['ports'] = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT p.id, p.name, d.id AS device_id, d.name AS device, CASE WHEN p.untagged_vlan_id = :id THEN 'untagged' ELSE 'tagged' END AS membership
            FROM dcim_port p JOIN dcim_device d ON d.id = p.device_id
            WHERE p.untagged_vlan_id = :id OR EXISTS (SELECT 1 FROM dcim_port_vlan pv WHERE pv.port_id = p.id AND pv.vlan_id = :id)
            ORDER BY d.name, p.name
            SQL, ['id' => $id]);

        return $v;
    }

    /** @param array<string, mixed> $data */
    public function saveVlan(?int $id, array $data): int
    {
        $p = new Payload($data);
        $vid = $p->int('vid', 1, 4094, required: true);
        $name = $p->string('name', 64, required: true);
        $groupId = $p->int('group_id', 1);
        $branchId = $p->int('branch_id', 1);
        if (null !== $groupId) {
            $g = $this->db->fetchAssociative('SELECT min_vid, max_vid, branch_id FROM ipam_vlan_group WHERE id = ?', [$groupId]);
            if (false === $g) {
                $p->error('group_id', 'Grupo não encontrado.');
            } elseif (null !== $vid && ($vid < (int) $g['min_vid'] || $vid > (int) $g['max_vid'])) {
                $p->error('vid', \sprintf('O grupo aceita VID de %d a %d.', $g['min_vid'], $g['max_vid']));
            } else {
                $branchId ??= null === $g['branch_id'] ? null : (int) $g['branch_id'];
            }
        }
        if (null !== $branchId && false === $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ?', [$branchId])) {
            $p->error('branch_id', 'Filial não encontrada.');
        }
        if (null !== $vid && false !== $this->db->fetchOne('SELECT 1 FROM ipam_vlan WHERE group_id IS NOT DISTINCT FROM ? AND vid = ? AND (group_id IS NOT NULL OR branch_id IS NOT DISTINCT FROM ?) AND id <> ?',
            [$groupId, $vid, $branchId, $id ?? 0])) {
            $p->error('vid', null === $groupId ? 'VID já usado (sem grupo) nesta filial.' : 'VID já usado neste grupo.');
        }
        $p->check();
        $row = ['vid' => $vid, 'name' => $name, 'group_id' => $groupId, 'branch_id' => $branchId, 'status' => $p->enum('status', array_keys(self::VLAN_STATUS), 'active'),
            'role' => $p->string('role', 64), 'description' => $p->string('description', 255)];
        $p->check();
        if (null === $id) {
            return (int) $this->db->fetchOne('INSERT INTO ipam_vlan (vid, name, group_id, branch_id, status, role, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [...array_values($row), Refs::now(), Refs::now()]);
        }
        if (0 === $this->db->update('ipam_vlan', $row + ['updated_at' => Refs::now()], ['id' => $id])) {
            throw new NotFoundException('VLAN não encontrada.');
        }

        return $id;
    }

    public function deleteVlan(int $id): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM ipam_vlan WHERE id = ?', [$id])) {
            throw new NotFoundException('VLAN não encontrada.');
        }
    }
}
