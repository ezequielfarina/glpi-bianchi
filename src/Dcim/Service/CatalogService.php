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

/**
 * Funções de equipamento, tipos de equipamento e modelos de porta (ADR-0016 §2).
 * Porta de DeviceRole/DeviceType e dos *component templates* do NetBox.
 */
final readonly class CatalogService
{
    public function __construct(
        private Connection $db,
        private Refs $refs,
        private ManageQuery $manage,
        private AttachmentStorage $attachments,
    ) {
    }

    // ------------------------------------------------------------------ funções

    /** @return list<array<string, mixed>> */
    public function roles(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT r.id, r.name, r.color, r.description, (SELECT count(*) FROM dcim_device d WHERE d.role_id = r.id) AS devices FROM dcim_device_role r ORDER BY r.name',
        );
    }

    /** @param array<string, mixed> $data */
    public function saveRole(?int $id, array $data): int
    {
        $p = new Payload($data);
        $name = $p->string('name', 100, required: true);
        $color = strtolower(ltrim((string) $p->string('color', 7), '#')) ?: '9e9e9e';
        if (1 !== preg_match('/^[0-9a-f]{6}$/', $color)) {
            $p->error('color', 'Cor inválida (hex, ex.: 2196f3).');
        }
        $description = $p->string('description', 255);
        if (null !== $name && false !== $this->db->fetchOne('SELECT 1 FROM dcim_device_role WHERE lower(name) = lower(?) AND id <> ?', [$name, $id ?? 0])) {
            $p->error('name', 'Já existe uma função com esse nome.');
        }
        if (null !== $id && false === $this->db->fetchOne('SELECT 1 FROM dcim_device_role WHERE id = ?', [$id])) {
            throw new NotFoundException('Função não encontrada.');
        }
        $p->check();
        $row = ['name' => $name, 'color' => $color, 'description' => $description];
        if (null === $id) {
            return (int) $this->db->fetchOne(
                'INSERT INTO dcim_device_role (name, color, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?) RETURNING id',
                [$name, $color, $description, Refs::now(), Refs::now()],
            );
        }
        $this->db->update('dcim_device_role', $row + ['updated_at' => Refs::now()], ['id' => $id]);

        return $id;
    }

    public function deleteRole(int $id): void
    {
        $used = (int) $this->db->fetchOne('SELECT count(*) FROM dcim_device WHERE role_id = ?', [$id]);
        if ($used > 0) {
            throw new ValidationException(['id' => \sprintf('Função usada por %d equipamento(s).', $used)]);
        }
        if (0 === $this->db->executeStatement('DELETE FROM dcim_device_role WHERE id = ?', [$id])) {
            throw new NotFoundException('Função não encontrada.');
        }
    }

    // ------------------------------------------------------------------ tipos de equipamento

    /** @return list<array<string, mixed>> */
    public function types(?string $q = null): array
    {
        return Num::rows($this->db->fetchAllAssociative(<<<'SQL'
            SELECT t.id, t.model, t.part_number, t.u_height::float8 AS u_height, t.is_full_depth, t.description, m.name AS manufacturer,
                   t.default_role_id, r.name AS default_role, r.color,
                   (SELECT count(*) FROM dcim_device d WHERE d.device_type_id = t.id) AS devices,
                   (SELECT count(*) FROM dcim_port_template p WHERE p.device_type_id = t.id AND p.kind = 'interface') AS interfaces,
                   (SELECT count(*) FROM dcim_port_template p WHERE p.device_type_id = t.id AND p.kind = 'front') AS front_ports,
                   (SELECT count(*) FROM dcim_port_template p WHERE p.device_type_id = t.id AND p.kind = 'rear') AS rear_ports,
                   (SELECT count(*) FROM dcim_port_template p WHERE p.device_type_id = t.id AND p.kind IN ('power_port', 'power_outlet')) AS power_ports,
                   (SELECT count(*) FROM dcim_port_template p WHERE p.device_type_id = t.id AND p.kind IN ('console', 'console_server')) AS console_ports,
                   (SELECT a.uuid FROM attachment a WHERE a.owner_type = 'device_type' AND a.owner_id = t.id AND a.kind = 'photo' ORDER BY a.created_at LIMIT 1) AS photo
            FROM dcim_device_type t
            JOIN manufacturer m ON m.id = t.manufacturer_id
            LEFT JOIN dcim_device_role r ON r.id = t.default_role_id
            WHERE CAST(:q AS text) IS NULL OR t.model ILIKE '%' || CAST(:q AS text) || '%' OR m.name ILIKE '%' || CAST(:q AS text) || '%'
            ORDER BY m.name, t.model
            SQL, ['q' => $q]), ['u_height'], ['devices', 'interfaces', 'front_ports', 'rear_ports', 'power_ports', 'console_ports']);
    }

    /** @return array<string, mixed> */
    public function type(int $id): array
    {
        $t = $this->db->fetchAssociative(<<<'SQL'
            SELECT t.id, t.model, t.part_number, t.u_height::float8 AS u_height, t.is_full_depth, t.description, t.comments,
                   t.manufacturer_id, m.name AS manufacturer, t.default_role_id, r.name AS default_role, r.color,
                   (SELECT count(*) FROM dcim_device d WHERE d.device_type_id = t.id) AS devices
            FROM dcim_device_type t JOIN manufacturer m ON m.id = t.manufacturer_id LEFT JOIN dcim_device_role r ON r.id = t.default_role_id
            WHERE t.id = ?
            SQL, [$id]) ?: throw new NotFoundException('Tipo de equipamento não encontrado.');
        $t = Num::row($t, ['u_height'], ['devices']);
        $t['templates'] = $this->templates($id);
        $t['attachments'] = $this->manage->attachments('device_type', $id);

        return $t;
    }

    /** @return list<array<string, mixed>> */
    public function templates(int $typeId): array
    {
        return PortRules::sort($this->db->fetchAllAssociative(<<<'SQL'
            SELECT p.id, p.kind, p.name, p.label, p.type, p.positions, p.mgmt_only, p.maximum_draw, p.allocated_draw, p.description,
                   p.rear_port_template_id, r.name AS rear_port, p.rear_position
            FROM dcim_port_template p LEFT JOIN dcim_port_template r ON r.id = p.rear_port_template_id
            WHERE p.device_type_id = ?
            SQL, [$typeId]));
    }

    /** @param array<string, mixed> $data */
    public function saveType(?int $id, array $data): int
    {
        $p = new Payload($data);
        $manufacturer = $p->string('manufacturer', 255, required: true);
        $model = $p->string('model', 150, required: true);
        $partNumber = $p->string('part_number', 100);
        $uRaw = $p->string('u_height', 6);
        $uNum = str_replace(',', '.', (string) $uRaw);
        $uHeight = null === $uRaw ? 1.0 : (is_numeric($uNum) ? (float) $uNum : -1.0);
        if ($uHeight < 0 || $uHeight > 60 || fmod($uHeight * 2, 1.0) !== 0.0) {
            $p->error('u_height', 'Altura em U entre 0 e 60, em passos de 0,5.');
        }
        $roleId = $p->int('default_role_id', 1);
        if (null !== $roleId && false === $this->db->fetchOne('SELECT 1 FROM dcim_device_role WHERE id = ?', [$roleId])) {
            $p->error('default_role_id', 'Função não encontrada.');
        }
        $p->check();
        $manufacturerId = (int) $this->refs->manufacturerId($manufacturer);
        if (false !== $this->db->fetchOne('SELECT 1 FROM dcim_device_type WHERE manufacturer_id = ? AND lower(model) = lower(?) AND id <> ?', [$manufacturerId, $model, $id ?? 0])) {
            throw new ValidationException(['model' => 'Já existe esse modelo para o fabricante.']);
        }
        if (null !== $id) {
            $current = $this->db->fetchAssociative('SELECT u_height::float8 AS u FROM dcim_device_type WHERE id = ?', [$id]) ?: throw new NotFoundException('Tipo de equipamento não encontrado.');
            if ((float) $current['u'] !== $uHeight && false !== $this->db->fetchOne('SELECT 1 FROM dcim_device WHERE device_type_id = ? AND position IS NOT NULL LIMIT 1', [$id])) {
                throw new ValidationException(['u_height' => 'Há equipamentos deste tipo montados em rack; retire-os do rack antes de mudar a altura.']);
            }
        }
        $row = ['manufacturer_id' => $manufacturerId, 'model' => $model, 'part_number' => $partNumber, 'u_height' => $uHeight,
            'is_full_depth' => $p->bool('is_full_depth', true) ? 'true' : 'false', 'default_role_id' => $roleId,
            'description' => $p->string('description', 255), 'comments' => $p->string('comments', 10000)];
        if (null === $id) {
            return (int) $this->db->fetchOne(
                'INSERT INTO dcim_device_type (manufacturer_id, model, part_number, u_height, is_full_depth, default_role_id, description, comments, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [...array_values($row), Refs::now(), Refs::now()],
            );
        }
        $this->db->update('dcim_device_type', $row + ['updated_at' => Refs::now()], ['id' => $id]);

        return $id;
    }

    public function deleteType(int $id): void
    {
        $used = (int) $this->db->fetchOne('SELECT count(*) FROM dcim_device WHERE device_type_id = ?', [$id]);
        if ($used > 0) {
            throw new ValidationException(['id' => \sprintf('Tipo usado por %d equipamento(s).', $used)]);
        }
        $this->attachments->deleteAllOf('device_type', $id);
        if (0 === $this->db->executeStatement('DELETE FROM dcim_device_type WHERE id = ?', [$id])) {
            throw new NotFoundException('Tipo de equipamento não encontrado.');
        }
    }

    // ------------------------------------------------------------------ modelos de porta

    /**
     * Cria modelos de porta em lote. `name` aceita padrão ("[1-24]", "Gi1/0/[1-48]").
     * kind = "pair" cria porta traseira + frontal com o mesmo nome, já mapeadas (patch panel/tomada).
     * kind = "front" com `rear_port_template_id` mapeia em sequência a partir de `rear_position`.
     *
     * @param array<string, mixed> $data
     *
     * @return list<int>
     */
    public function addTemplates(int $typeId, array $data): array
    {
        if (false === $this->db->fetchOne('SELECT 1 FROM dcim_device_type WHERE id = ?', [$typeId])) {
            throw new NotFoundException('Tipo de equipamento não encontrado.');
        }
        $p = new Payload($data);
        $kind = $p->enum('kind', [...array_keys(DcimChoices::PORT_KINDS), 'pair']);
        $pattern = $p->string('name', 120, required: true);
        $p->check();
        $names = NamePattern::expand((string) $pattern);
        $f = PortRules::read($p, 'pair' === $kind ? 'front' : (string) $kind);
        $rearType = 'pair' === $kind ? ($p->string('rear_type', 32) ?? '110-punch') : null;
        if (null !== $rearType && !isset(DcimChoices::PORT_TYPES['rear'][$rearType])) {
            $p->error('rear_type', 'Tipo físico inválido para a porta traseira.');
        }
        $rear = null;
        $startPos = 1;
        if ('front' === $kind) {
            $rearId = $p->int('rear_port_template_id', 1, required: true);
            $startPos = $p->int('rear_position', 1, 1024) ?? 1;
            $rear = null === $rearId ? null : $this->db->fetchAssociative(
                "SELECT id, positions FROM dcim_port_template WHERE id = ? AND device_type_id = ? AND kind = 'rear'", [$rearId, $typeId],
            );
            if (null !== $rearId && false === $rear) {
                $p->error('rear_port_template_id', 'Porta traseira não encontrada neste tipo.');
                $rear = null;
            }
            if (\is_array($rear) && $startPos + \count($names) - 1 > (int) $rear['positions']) {
                $p->error('rear_position', \sprintf('A porta traseira tem %d posição(ões).', $rear['positions']));
            }
        }
        $p->check();

        return $this->db->transactional(function () use ($typeId, $kind, $names, $f, $rearType, $rear, $startPos): array {
            $ids = [];
            foreach ($names as $i => $name) {
                $this->assertFreeTemplateName($typeId, 'pair' === $kind ? 'front' : (string) $kind, $name);
                if ('pair' === $kind) {
                    $this->assertFreeTemplateName($typeId, 'rear', $name);
                    $rid = $this->insertTemplate($typeId, 'rear', $name, ['type' => $rearType] + $f, null, null);
                    $ids[] = $this->insertTemplate($typeId, 'front', $name, $f, $rid, 1);
                    continue;
                }
                if (\is_array($rear)) {
                    $pos = $startPos + $i;
                    if (false !== $this->db->fetchOne('SELECT 1 FROM dcim_port_template WHERE rear_port_template_id = ? AND rear_position = ?', [$rear['id'], $pos])) {
                        throw new ValidationException(['rear_position' => \sprintf('A posição %d da porta traseira já está mapeada.', $pos)]);
                    }
                    $ids[] = $this->insertTemplate($typeId, 'front', $name, $f, (int) $rear['id'], $pos);
                    continue;
                }
                $ids[] = $this->insertTemplate($typeId, (string) $kind, $name, $f, null, null);
            }

            return $ids;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateTemplate(int $id, array $data): void
    {
        $t = $this->db->fetchAssociative('SELECT id, device_type_id, kind FROM dcim_port_template WHERE id = ?', [$id]) ?: throw new NotFoundException('Modelo de porta não encontrado.');
        $p = new Payload($data);
        $name = $p->string('name', 64, required: true);
        $f = PortRules::read($p, (string) $t['kind']);
        if ('rear' === $t['kind']) {
            $mapped = (int) $this->db->fetchOne('SELECT coalesce(max(rear_position), 0) FROM dcim_port_template WHERE rear_port_template_id = ?', [$id]);
            if ($f['positions'] < $mapped) {
                $p->error('positions', \sprintf('Há porta frontal mapeada na posição %d.', $mapped));
            }
        }
        $p->check();
        $this->assertFreeTemplateName((int) $t['device_type_id'], (string) $t['kind'], (string) $name, $id);
        $this->db->update('dcim_port_template', ['name' => $name, 'label' => $f['label'], 'type' => $f['type'], 'positions' => $f['positions'],
            'mgmt_only' => $f['mgmt_only'] ? 'true' : 'false', 'maximum_draw' => $f['maximum_draw'], 'allocated_draw' => $f['allocated_draw'],
            'description' => $f['description']], ['id' => $id]);
    }

    public function deleteTemplate(int $id): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM dcim_port_template WHERE id = ?', [$id])) {
            throw new NotFoundException('Modelo de porta não encontrado.');
        }
    }

    /** @param array<string, mixed> $f */
    private function insertTemplate(int $typeId, string $kind, string $name, array $f, ?int $rearId, ?int $rearPos): int
    {
        return (int) $this->db->fetchOne(
            'INSERT INTO dcim_port_template (device_type_id, kind, name, label, type, positions, mgmt_only, maximum_draw, allocated_draw, description, rear_port_template_id, rear_position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$typeId, $kind, $name, $f['label'], $f['type'], 'rear' === $kind ? $f['positions'] : 1, ($f['mgmt_only'] ?? false) ? 'true' : 'false',
                $f['maximum_draw'], $f['allocated_draw'], $f['description'], $rearId, $rearPos],
        );
    }

    private function assertFreeTemplateName(int $typeId, string $kind, string $name, int $except = 0): void
    {
        if (false !== $this->db->fetchOne('SELECT 1 FROM dcim_port_template WHERE device_type_id = ? AND kind = ? AND name = ? AND id <> ?', [$typeId, $kind, $name, $except])) {
            throw new ValidationException(['name' => \sprintf('Já existe a porta "%s" (%s) neste tipo.', $name, DcimChoices::PORT_KINDS[$kind] ?? $kind)]);
        }
    }
}
