<?php

declare(strict_types=1);

namespace App\Dcim\Service;

use App\Attachment\Service\AttachmentStorage;
use App\Ui\ReadModel\ManageQuery;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Locais por filial (ADR-0016 §1): árvore `location` (ltree, ADR-0004) com `branch_id` e `kind`.
 * Porta de `dcim.Location` (netbox/dcim/models/sites.py): o filho herda a filial do pai.
 */
final readonly class LocationService
{
    public function __construct(
        private Connection $db,
        private Refs $refs,
        private ManageQuery $manage,
        private AttachmentStorage $attachments,
    ) {
    }

    /**
     * Árvore achatada em ordem de exibição (filial → pai → filhos por nome), com profundidade e contagens.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(?int $branchId = null): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT l.id, l.parent_id, l.name, l.code, l.kind, l.description, l.branch_id, b.name AS branch, b.code AS branch_code,
                   (SELECT count(*) FROM dcim_rack r WHERE r.location_id = l.id) AS racks,
                   (SELECT count(*) FROM dcim_device d WHERE d.location_id = l.id) AS devices,
                   (SELECT count(*) FROM asset a WHERE a.location_id = l.id AND a.deleted_at IS NULL) AS assets,
                   (SELECT count(*) FROM attachment t WHERE t.owner_type = 'location' AND t.owner_id = l.id AND t.kind = 'photo') AS photos
            FROM location l
            LEFT JOIN branch b ON b.id = l.branch_id
            WHERE (CAST(:branch AS int) IS NULL OR l.branch_id = :branch)
            SQL, ['branch' => $branchId]);

        $children = [];
        foreach ($rows as $r) {
            $children[(int) ($r['parent_id'] ?? 0)][] = $r;
        }
        $byName = static fn (array $a, array $b): int => [(string) $a['branch'], (string) $a['name']] <=> [(string) $b['branch'], (string) $b['name']];
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $children, $byName): void {
            $list = $children[$parent] ?? [];
            usort($list, $byName);
            foreach ($list as $r) {
                $r['depth'] = $depth;
                $r['kind_label'] = DcimChoices::LOCATION_KINDS[(string) $r['kind']] ?? $r['kind'];
                $out[] = $r;
                $walk((int) $r['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        // nós cujo pai ficou fora do filtro por filial
        $listed = array_column($out, 'id');
        foreach ($rows as $r) {
            if (!\in_array($r['id'], $listed, true) && !\in_array($r['parent_id'], $listed, true)) {
                $r['depth'] = 0;
                $r['kind_label'] = DcimChoices::LOCATION_KINDS[(string) $r['kind']] ?? $r['kind'];
                $out[] = $r;
                $walk((int) $r['id'], 1);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $l = $this->db->fetchAssociative(<<<'SQL'
            SELECT l.id, l.parent_id, l.name, l.code, l.kind, l.description, l.address, l.branch_id, b.name AS branch, l.path::text AS path
            FROM location l LEFT JOIN branch b ON b.id = l.branch_id WHERE l.id = ?
            SQL, [$id]) ?: throw new NotFoundException('Local não encontrado.');
        $l['kind_label'] = DcimChoices::LOCATION_KINDS[(string) $l['kind']] ?? $l['kind'];
        $ancestors = array_map('intval', explode('.', (string) $l['path']));
        array_pop($ancestors);
        $l['breadcrumb'] = [] === $ancestors ? [] : $this->db->fetchAllAssociative(
            'SELECT id, name FROM location WHERE id IN (?) ORDER BY nlevel(path)', [$ancestors], [ArrayParameterType::INTEGER],
        );
        $l['children'] = $this->db->fetchAllAssociative(
            'SELECT id, name, kind FROM location WHERE parent_id = ? ORDER BY name', [$id],
        );
        $sub = 'SELECT id FROM location WHERE path <@ (SELECT path FROM location WHERE id = :id)';
        $l['racks'] = $this->db->fetchAllAssociative(<<<SQL
            SELECT r.id, r.name, r.status, r.u_height, r.location_id, lo.name AS location,
                   (SELECT coalesce(sum(t.u_height), 0)::float8 FROM dcim_device d JOIN dcim_device_type t ON t.id = d.device_type_id
                     WHERE d.rack_id = r.id AND d.position IS NOT NULL) AS used_u
            FROM dcim_rack r LEFT JOIN location lo ON lo.id = r.location_id
            WHERE r.location_id IN ($sub) ORDER BY r.name
            SQL, ['id' => $id]);
        $l['racks'] = Num::rows($l['racks'], ['used_u'], ['u_height']);
        $l['devices'] = $this->db->fetchAllAssociative(<<<SQL
            SELECT d.id, d.name, d.status, r.name AS role, r.color, t.model AS device_type, k.name AS rack, d.position, lo.name AS location
            FROM dcim_device d JOIN dcim_device_role r ON r.id = d.role_id JOIN dcim_device_type t ON t.id = d.device_type_id
            LEFT JOIN dcim_rack k ON k.id = d.rack_id LEFT JOIN location lo ON lo.id = d.location_id
            WHERE d.location_id IN ($sub) ORDER BY d.name
            SQL, ['id' => $id]);
        $l['devices'] = Num::rows($l['devices'], ['position']);
        $l['assets'] = $this->db->fetchAllAssociative(<<<SQL
            SELECT a.uuid, a.name, a.inventory_number, t.label AS type, lo.name AS location
            FROM asset a JOIN asset_type t ON t.id = a.asset_type_id LEFT JOIN location lo ON lo.id = a.location_id
            WHERE a.deleted_at IS NULL AND a.location_id IN ($sub) ORDER BY a.name LIMIT 500
            SQL, ['id' => $id]);
        $l['attachments'] = $this->manage->attachments('location', $id);

        return $l;
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $name = $p->string('name', 255, required: true);
        $code = $p->string('code', 64);
        $kind = $p->enum('kind', DcimChoices::keys('LOCATION_KINDS'), 'room');
        $description = $p->string('description', 255);
        $parentId = $p->int('parent_id', 1);
        $branchId = $p->int('branch_id', 1);
        $current = null;
        if (null !== $id) {
            $current = $this->db->fetchAssociative('SELECT id, branch_id, path::text AS path FROM location WHERE id = ?', [$id])
                ?: throw new NotFoundException('Local não encontrado.');
        }

        if (null !== $parentId) {
            $parent = $this->db->fetchAssociative('SELECT id, branch_id, path::text AS path FROM location WHERE id = ?', [$parentId]);
            if (false === $parent) {
                $p->error('parent_id', 'Local pai não encontrado.');
            } else {
                if (null !== $current && ($parentId === $id || str_starts_with((string) $parent['path'].'.', (string) $current['path'].'.'))) {
                    $p->error('parent_id', 'Um local não pode ficar dentro dele mesmo.');
                }
                $branchId = null === $parent['branch_id'] ? $branchId : (int) $parent['branch_id']; // herda a filial do pai
            }
        }
        if (null === $branchId) {
            $p->error('branch_id', 'Informe a filial.');
        } elseif (false === $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ?', [$branchId])) {
            $p->error('branch_id', 'Filial não encontrada.');
        }
        if (null !== $name && false !== $this->db->fetchOne(
            'SELECT 1 FROM location WHERE lower(name) = lower(?) AND parent_id IS NOT DISTINCT FROM ? AND branch_id IS NOT DISTINCT FROM ? AND id <> ?',
            [$name, $parentId, $branchId, $id ?? 0],
        )) {
            $p->error('name', 'Já existe um local com esse nome neste nível.');
        }
        if (null !== $current && null !== $branchId && (int) ($current['branch_id'] ?? 0) !== $branchId && null !== $current['branch_id']) {
            $used = (int) $this->db->fetchOne(<<<'SQL'
                SELECT (SELECT count(*) FROM dcim_rack WHERE location_id IN (SELECT id FROM location WHERE path <@ CAST(:p AS ltree)))
                     + (SELECT count(*) FROM dcim_device WHERE location_id IN (SELECT id FROM location WHERE path <@ CAST(:p AS ltree)))
                SQL, ['p' => $current['path']]);
            if ($used > 0) {
                $p->error('branch_id', 'Há racks/equipamentos neste local (ou abaixo dele). Mova-os antes de trocar a filial.');
            }
        }
        $p->check();

        $row = ['name' => $name, 'code' => $code, 'kind' => $kind, 'description' => $description, 'parent_id' => $parentId, 'branch_id' => $branchId];

        return (int) $this->db->transactional(function () use ($id, $row): int {
            if (null === $id) {
                return (int) $this->db->fetchOne(
                    'INSERT INTO location (organization_id, name, code, kind, description, parent_id, branch_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                    [$this->refs->rootOrganizationId(), ...array_values($row), Refs::now(), Refs::now()],
                );
            }
            $this->db->update('location', $row + ['updated_at' => Refs::now()], ['id' => $id]);
            // a subárvore acompanha a filial
            $this->db->executeStatement(
                'UPDATE location SET branch_id = ? WHERE path <@ (SELECT path FROM location WHERE id = ?) AND branch_id IS DISTINCT FROM ?',
                [$row['branch_id'], $id, $row['branch_id']],
            );
            return $id;
        });
    }

    public function delete(int $id): void
    {
        $l = $this->db->fetchAssociative('SELECT id FROM location WHERE id = ?', [$id]) ?: throw new NotFoundException('Local não encontrado.');
        $use = $this->db->fetchAssociative(<<<'SQL'
            SELECT (SELECT count(*) FROM location WHERE parent_id = :id) AS children,
                   (SELECT count(*) FROM dcim_rack WHERE location_id = :id) AS racks,
                   (SELECT count(*) FROM dcim_device WHERE location_id = :id) AS devices,
                   (SELECT count(*) FROM asset WHERE location_id = :id AND deleted_at IS NULL) AS assets
            SQL, ['id' => $l['id']]) ?: [];
        $msg = [];
        foreach (['children' => 'subloca(is)', 'racks' => 'rack(s)', 'devices' => 'equipamento(s)', 'assets' => 'ativo(s)'] as $k => $label) {
            if ((int) ($use[$k] ?? 0) > 0) {
                $msg[] = $use[$k].' '.$label;
            }
        }
        if ([] !== $msg) {
            throw new ValidationException(['id' => 'O local ainda tem '.implode(', ', $msg).'. Mova ou exclua antes.']);
        }
        $this->attachments->deleteAllOf('location', $id);
        $this->db->executeStatement('DELETE FROM location WHERE id = ?', [$id]);
    }
}
