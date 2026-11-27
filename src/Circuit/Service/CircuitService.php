<?php

declare(strict_types=1);

namespace App\Circuit\Service;

use App\Attachment\Service\AttachmentStorage;
use App\Ui\ReadModel\ManageQuery;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;

/**
 * Circuitos de operadora (ADR-0016 §6). Porta de `circuits.Circuit` / `CircuitTermination`
 * (netbox/circuits/models/circuits.py); a operadora (Provider) é o fornecedor (`supplier`).
 */
final readonly class CircuitService
{
    public const array STATUS = [
        'planned' => 'Planejado', 'provisioning' => 'Em instalação', 'active' => 'Ativo', 'offline' => 'Fora do ar',
        'deprovisioning' => 'Em cancelamento', 'decommissioned' => 'Cancelado',
    ];

    public function __construct(
        private Connection $db,
        private ManageQuery $manage,
        private AttachmentStorage $attachments,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function types(): array
    {
        return $this->db->fetchAllAssociative('SELECT t.id, t.name, t.description, (SELECT count(*) FROM circuit c WHERE c.type_id = t.id) AS circuits FROM circuit_type t ORDER BY t.name');
    }

    /** @param array<string, mixed> $data */
    public function saveType(?int $id, array $data): int
    {
        $p = new Payload($data);
        $name = $p->string('name', 100, required: true);
        $description = $p->string('description', 255);
        if (null !== $name && false !== $this->db->fetchOne('SELECT 1 FROM circuit_type WHERE lower(name) = lower(?) AND id <> ?', [$name, $id ?? 0])) {
            $p->error('name', 'Já existe um tipo com esse nome.');
        }
        $p->check();
        if (null === $id) {
            return (int) $this->db->fetchOne('INSERT INTO circuit_type (name, description, created_at, updated_at) VALUES (?, ?, ?, ?) RETURNING id', [$name, $description, Refs::now(), Refs::now()]);
        }
        if (0 === $this->db->update('circuit_type', ['name' => $name, 'description' => $description, 'updated_at' => Refs::now()], ['id' => $id])) {
            throw new NotFoundException('Tipo de circuito não encontrado.');
        }

        return $id;
    }

    public function deleteType(int $id): void
    {
        $n = (int) $this->db->fetchOne('SELECT count(*) FROM circuit WHERE type_id = ?', [$id]);
        if ($n > 0) {
            throw new ValidationException(['id' => \sprintf('Tipo usado por %d circuito(s).', $n)]);
        }
        if (0 === $this->db->executeStatement('DELETE FROM circuit_type WHERE id = ?', [$id])) {
            throw new NotFoundException('Tipo de circuito não encontrado.');
        }
    }

    /**
     * @param array{q?: string|null, branch?: int|null, supplier?: int|null, status?: string|null} $f
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $f = []): array
    {
        return $this->db->fetchAllAssociative(<<<'SQL'
            SELECT c.id, c.cid, c.status, c.commit_rate_kbps, c.install_date, c.termination_date, c.description,
                   c.supplier_id, s.name AS provider, t.name AS type, c.contract_id, k.number AS contract_number,
                   (SELECT json_object_agg(x.term_side, json_build_object('id', x.id, 'branch', b.name, 'location', l.name, 'provider_network', x.provider_network,
                           'cable_id', ct.cable_id))
                      FROM circuit_termination x LEFT JOIN branch b ON b.id = x.branch_id LEFT JOIN location l ON l.id = x.location_id
                      LEFT JOIN dcim_cable_termination ct ON ct.circuit_termination_id = x.id
                     WHERE x.circuit_id = c.id) AS terminations
            FROM circuit c
            JOIN supplier s ON s.id = c.supplier_id
            JOIN circuit_type t ON t.id = c.type_id
            LEFT JOIN contract k ON k.id = c.contract_id
            WHERE (CAST(:supplier AS int) IS NULL OR c.supplier_id = :supplier)
              AND (CAST(:status AS text) IS NULL OR c.status = :status)
              AND (CAST(:branch AS int) IS NULL OR EXISTS (SELECT 1 FROM circuit_termination x WHERE x.circuit_id = c.id AND x.branch_id = :branch))
              AND (CAST(:q AS text) IS NULL OR c.cid ILIKE '%' || CAST(:q AS text) || '%' OR s.name ILIKE '%' || CAST(:q AS text) || '%'
                   OR c.description ILIKE '%' || CAST(:q AS text) || '%')
            ORDER BY s.name, c.cid
            SQL, ['supplier' => $f['supplier'] ?? null, 'status' => $f['status'] ?? null, 'branch' => $f['branch'] ?? null, 'q' => $f['q'] ?? null]);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $c = $this->db->fetchAssociative(<<<'SQL'
            SELECT c.*, s.name AS provider, s.phone AS provider_phone, s.email AS provider_email, t.name AS type, k.number AS contract_number, k.title AS contract_title
            FROM circuit c JOIN supplier s ON s.id = c.supplier_id JOIN circuit_type t ON t.id = c.type_id LEFT JOIN contract k ON k.id = c.contract_id
            WHERE c.id = ?
            SQL, [$id]) ?: throw new NotFoundException('Circuito não encontrado.');
        $c['terminations'] = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT x.*, b.name AS branch, l.name AS location, ct.cable_id, cb.label AS cable_label, cb.color AS cable_color,
                   (SELECT string_agg(d.name || ' ' || p.name, ', ') FROM dcim_cable_termination o JOIN dcim_port p ON p.id = o.port_id JOIN dcim_device d ON d.id = p.device_id
                     WHERE o.cable_id = ct.cable_id AND o.cable_end <> ct.cable_end) AS peer
            FROM circuit_termination x
            LEFT JOIN branch b ON b.id = x.branch_id LEFT JOIN location l ON l.id = x.location_id
            LEFT JOIN dcim_cable_termination ct ON ct.circuit_termination_id = x.id LEFT JOIN dcim_cable cb ON cb.id = ct.cable_id
            WHERE x.circuit_id = ? ORDER BY x.term_side
            SQL, [$id]);
        $c['attachments'] = $this->manage->attachments('circuit', $id);

        return $c;
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $supplierId = $p->int('supplier_id', 1, required: true);
        $typeId = $p->int('type_id', 1, required: true);
        $contractId = $p->int('contract_id', 1);
        $cid = $p->string('cid', 100, required: true);
        $install = $p->date('install_date');
        $termination = $p->date('termination_date');
        if (null !== $supplierId && false === $this->db->fetchOne('SELECT 1 FROM supplier WHERE id = ?', [$supplierId])) {
            $p->error('supplier_id', 'Operadora (fornecedor) não encontrada.');
        }
        if (null !== $typeId && false === $this->db->fetchOne('SELECT 1 FROM circuit_type WHERE id = ?', [$typeId])) {
            $p->error('type_id', 'Tipo de circuito não encontrado.');
        }
        if (null !== $contractId && false === $this->db->fetchOne('SELECT 1 FROM contract WHERE id = ?', [$contractId])) {
            $p->error('contract_id', 'Contrato não encontrado.');
        }
        if (null !== $install && null !== $termination && $termination < $install) {
            $p->error('termination_date', 'A desativação não pode ser antes da ativação.');
        }
        if (null !== $cid && false !== $this->db->fetchOne('SELECT 1 FROM circuit WHERE supplier_id = ? AND lower(cid) = lower(?) AND id <> ?', [$supplierId, $cid, $id ?? 0])) {
            $p->error('cid', 'Já existe esse circuito nesta operadora.');
        }
        $p->check();
        $row = ['supplier_id' => $supplierId, 'type_id' => $typeId, 'contract_id' => $contractId, 'cid' => $cid,
            'status' => $p->enum('status', array_keys(self::STATUS), 'active'), 'commit_rate_kbps' => $p->int('commit_rate_kbps', 1, 2_000_000_000),
            'install_date' => $install, 'termination_date' => $termination, 'description' => $p->string('description', 255), 'comments' => $p->string('comments', 10000)];
        $p->check();
        if (null === $id) {
            $cols = implode(', ', array_keys($row));
            $marks = implode(', ', array_fill(0, \count($row) + 2, '?'));

            return (int) $this->db->fetchOne("INSERT INTO circuit ($cols, created_at, updated_at) VALUES ($marks) RETURNING id", [...array_values($row), Refs::now(), Refs::now()]);
        }
        if (0 === $this->db->update('circuit', $row + ['updated_at' => Refs::now()], ['id' => $id])) {
            throw new NotFoundException('Circuito não encontrado.');
        }

        return $id;
    }

    public function delete(int $id): void
    {
        $this->db->transactional(function () use ($id): void {
            $this->db->executeStatement('DELETE FROM dcim_cable WHERE id IN (SELECT t.cable_id FROM dcim_cable_termination t JOIN circuit_termination x ON x.id = t.circuit_termination_id WHERE x.circuit_id = ?)', [$id]);
            if (0 === $this->db->executeStatement('DELETE FROM circuit WHERE id = ?', [$id])) {
                throw new NotFoundException('Circuito não encontrado.');
            }
        });
        $this->attachments->deleteAllOf('circuit', $id);
    }

    /**
     * Cria/atualiza a terminação do lado A ou Z: na filial/local (onde o cabo chega) ou na rede da operadora.
     *
     * @param array<string, mixed> $data
     */
    public function saveTermination(int $circuitId, string $side, array $data): int
    {
        if (!\in_array($side, ['A', 'Z'], true)) {
            throw new ValidationException(['term_side' => 'Lado A ou Z.']);
        }
        if (false === $this->db->fetchOne('SELECT 1 FROM circuit WHERE id = ?', [$circuitId])) {
            throw new NotFoundException('Circuito não encontrado.');
        }
        $p = new Payload($data);
        $branchId = $p->int('branch_id', 1);
        $locationId = $p->int('location_id', 1);
        $providerNetwork = $p->string('provider_network', 100);
        if ((null === $branchId) === (null === $providerNetwork)) {
            $p->error('branch_id', 'Informe a filial onde o circuito chega OU a rede da operadora.');
        }
        if (null !== $branchId && false === $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ?', [$branchId])) {
            $p->error('branch_id', 'Filial não encontrada.');
        }
        if (null !== $locationId && false === $this->db->fetchOne('SELECT 1 FROM location WHERE id = ? AND branch_id IS NOT DISTINCT FROM ?', [$locationId, $branchId])) {
            $p->error('location_id', 'O local precisa ser da mesma filial.');
        }
        $existing = $this->db->fetchAssociative(
            'SELECT x.id, t.cable_id FROM circuit_termination x LEFT JOIN dcim_cable_termination t ON t.circuit_termination_id = x.id WHERE x.circuit_id = ? AND x.term_side = ?',
            [$circuitId, $side],
        );
        if (null !== $providerNetwork && \is_array($existing) && null !== $existing['cable_id']) {
            $p->error('provider_network', 'Este lado tem cabo; remova o cabo antes de apontar para a rede da operadora.');
        }
        $p->check();
        $row = ['branch_id' => $branchId, 'location_id' => null === $branchId ? null : $locationId, 'provider_network' => $providerNetwork,
            'port_speed_kbps' => $p->int('port_speed_kbps', 1, 2_000_000_000), 'upstream_speed_kbps' => $p->int('upstream_speed_kbps', 1, 2_000_000_000),
            'xconnect_id' => $p->string('xconnect_id', 50), 'pp_info' => $p->string('pp_info', 100), 'description' => $p->string('description', 255)];
        $p->check();
        if (\is_array($existing)) {
            $this->db->update('circuit_termination', $row + ['updated_at' => Refs::now()], ['id' => $existing['id']]);

            return (int) $existing['id'];
        }
        $cols = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, \count($row) + 4, '?'));

        return (int) $this->db->fetchOne(
            "INSERT INTO circuit_termination (circuit_id, term_side, $cols, created_at, updated_at) VALUES ($marks) RETURNING id",
            [$circuitId, $side, ...array_values($row), Refs::now(), Refs::now()],
        );
    }

    public function deleteTermination(int $id): void
    {
        $this->db->transactional(function () use ($id): void {
            $this->db->executeStatement('DELETE FROM dcim_cable WHERE id IN (SELECT cable_id FROM dcim_cable_termination WHERE circuit_termination_id = ?)', [$id]);
            if (0 === $this->db->executeStatement('DELETE FROM circuit_termination WHERE id = ?', [$id])) {
                throw new NotFoundException('Terminação não encontrada.');
            }
        });
    }
}
