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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Cabos (ADR-0016 §4). Porta de `dcim.Cable` + `CableTermination` (netbox/dcim/models/cables.py),
 * com as validações de `Cable.clean()` / `CableTermination.clean()`:
 *  - as duas pontas com ao menos uma terminação; no máximo uma ponta por porta;
 *  - terminações de uma ponta: mesmo tipo e mesmo equipamento (ou mesmo circuito);
 *  - tipos compatíveis entre as pontas (COMPATIBLE_TERMINATION_TYPES);
 *  - interface virtual/LAG/wireless e porta "marcada como conectada" não recebem cabo;
 *  - terminação de circuito que vai para a nuvem da operadora (provider_network) não recebe cabo.
 */
final readonly class CableService
{
    public function __construct(
        private Connection $db,
        private ManageQuery $manage,
        private AttachmentStorage $attachments,
    ) {
    }

    /**
     * @param array{q?: string|null, branch?: int|null, device?: int|null, type?: string|null, status?: string|null} $f
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $f = []): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT c.id, c.label, c.type, c.status, c.color, c.length::float8 AS length, c.length_unit, c.description
            FROM dcim_cable c
            WHERE (CAST(:type AS text) IS NULL OR c.type = :type)
              AND (CAST(:status AS text) IS NULL OR c.status = :status)
              AND (CAST(:device AS int) IS NULL OR EXISTS (SELECT 1 FROM dcim_cable_termination t JOIN dcim_port p ON p.id = t.port_id WHERE t.cable_id = c.id AND p.device_id = :device))
              AND (CAST(:branch AS int) IS NULL OR EXISTS (
                    SELECT 1 FROM dcim_cable_termination t
                    LEFT JOIN dcim_port p ON p.id = t.port_id LEFT JOIN dcim_device d ON d.id = p.device_id
                    LEFT JOIN circuit_termination ct ON ct.id = t.circuit_termination_id
                    WHERE t.cable_id = c.id AND coalesce(d.branch_id, ct.branch_id) = :branch))
              AND (CAST(:q AS text) IS NULL OR c.label ILIKE '%' || CAST(:q AS text) || '%' OR c.description ILIKE '%' || CAST(:q AS text) || '%'
                   OR EXISTS (SELECT 1 FROM dcim_cable_termination t JOIN dcim_port p ON p.id = t.port_id JOIN dcim_device d ON d.id = p.device_id
                              WHERE t.cable_id = c.id AND (d.name ILIKE '%' || CAST(:q AS text) || '%')))
            ORDER BY c.label NULLS LAST, c.id DESC
            LIMIT 2000
            SQL, ['type' => $f['type'] ?? null, 'status' => $f['status'] ?? null, 'device' => $f['device'] ?? null, 'branch' => $f['branch'] ?? null, 'q' => $f['q'] ?? null]);
        $ends = $this->ends(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $r['type_label'] = DcimChoices::CABLE_TYPES[(string) $r['type']] ?? null;
            $r['a'] = $ends[(int) $r['id']]['A'] ?? [];
            $r['b'] = $ends[(int) $r['id']]['B'] ?? [];
        }
        unset($r);

        return $rows;
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $c = $this->db->fetchAssociative('SELECT c.*, c.length::float8 AS length FROM dcim_cable c WHERE c.id = ?', [$id]) ?: throw new NotFoundException('Cabo não encontrado.');
        $c['type_label'] = DcimChoices::CABLE_TYPES[(string) $c['type']] ?? null;
        $ends = $this->ends([$id]);
        $c['a'] = $ends[$id]['A'] ?? [];
        $c['b'] = $ends[$id]['B'] ?? [];
        $c['attachments'] = $this->manage->attachments('cable', $id);

        return $c;
    }

    /**
     * @param array<string, mixed> $data a_terminations/b_terminations: [{type: "port"|"circuit", id: int}, ...]
     */
    public function save(?int $id, array $data): int
    {
        if (null !== $id && false === $this->db->fetchOne('SELECT 1 FROM dcim_cable WHERE id = ?', [$id])) {
            throw new NotFoundException('Cabo não encontrado.');
        }
        $p = new Payload($data);
        $attrs = $this->attributes($p);
        $replace = null === $id || $p->has('a_terminations') || $p->has('b_terminations');
        $a = $b = [];
        if ($replace) {
            $a = $this->terminations($p, 'a_terminations');
            $b = $this->terminations($p, 'b_terminations');
        }
        $p->check();
        if ($replace) {
            $this->validate($a, $b, $id);
        }

        try {
            return $this->db->transactional(function () use ($id, $attrs, $replace, $a, $b): int {
                if (null === $id) {
                    $id = (int) $this->db->fetchOne(
                        'INSERT INTO dcim_cable (label, type, status, color, length, length_unit, description, comments, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                        [...array_values($attrs), Refs::now(), Refs::now()],
                    );
                } else {
                    $this->db->update('dcim_cable', $attrs + ['updated_at' => Refs::now()], ['id' => $id]);
                }
                if ($replace) {
                    $this->db->executeStatement('DELETE FROM dcim_cable_termination WHERE cable_id = ?', [$id]);
                    foreach (['A' => $a, 'B' => $b] as $end => $list) {
                        foreach ($list as $t) {
                            $this->db->insert('dcim_cable_termination', ['cable_id' => $id, 'cable_end' => $end,
                                'port_id' => 'port' === $t['type'] ? $t['id'] : null, 'circuit_termination_id' => 'circuit' === $t['type'] ? $t['id'] : null]);
                        }
                    }
                }

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ValidationException(['a_terminations' => 'Uma das portas acabou de receber outro cabo. Recarregue e tente de novo.']);
        }
    }

    public function delete(int $id): void
    {
        $this->attachments->deleteAllOf('cable', $id);
        if (0 === $this->db->executeStatement('DELETE FROM dcim_cable WHERE id = ?', [$id])) {
            throw new NotFoundException('Cabo não encontrado.');
        }
    }

    /**
     * Resumo das pontas de cada cabo: equipamento/circuito + portas.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, list<array<string, mixed>>>>
     */
    public function ends(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAllAssociative(<<<'SQL'
            SELECT t.cable_id, t.cable_end, t.port_id, p.name AS port, p.kind, d.id AS device_id, d.name AS device, b.name AS branch,
                   k.name AS rack, l.name AS location, t.circuit_termination_id, ct.term_side, c.id AS circuit_id, c.cid, s.name AS provider
            FROM dcim_cable_termination t
            LEFT JOIN dcim_port p ON p.id = t.port_id
            LEFT JOIN dcim_device d ON d.id = p.device_id
            LEFT JOIN dcim_rack k ON k.id = d.rack_id
            LEFT JOIN circuit_termination ct ON ct.id = t.circuit_termination_id
            LEFT JOIN circuit c ON c.id = ct.circuit_id
            LEFT JOIN supplier s ON s.id = c.supplier_id
            LEFT JOIN branch b ON b.id = coalesce(d.branch_id, ct.branch_id)
            LEFT JOIN location l ON l.id = coalesce(d.location_id, ct.location_id)
            WHERE t.cable_id IN (?) ORDER BY t.id
            SQL, [$ids], [ArrayParameterType::INTEGER]) as $t) {
            $out[(int) $t['cable_id']][(string) $t['cable_end']][] = $t;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function attributes(Payload $p): array
    {
        $color = $p->string('color', 7);
        $color = null === $color ? null : strtolower(ltrim($color, '#'));
        if (null !== $color && 1 !== preg_match('/^[0-9a-f]{6}$/', $color)) {
            $p->error('color', 'Cor inválida.');
        }
        $lenRaw = $p->string('length', 12);
        $length = null;
        if (null !== $lenRaw) {
            $n = str_replace(',', '.', $lenRaw);
            if (!is_numeric($n) || (float) $n <= 0 || (float) $n > 999999) {
                $p->error('length', 'Comprimento inválido.');
            } else {
                $length = round((float) $n, 2);
            }
        }
        $unit = $p->enum('length_unit', DcimChoices::keys('LENGTH_UNITS'));

        return [
            'label' => $p->string('label', 100),
            'type' => $p->enum('type', DcimChoices::keys('CABLE_TYPES')),
            'status' => $p->enum('status', DcimChoices::keys('CABLE_STATUS'), 'connected'),
            'color' => $color,
            'length' => $length,
            'length_unit' => null === $length ? null : ($unit ?? 'm'),
            'description' => $p->string('description', 255),
            'comments' => $p->string('comments', 10000),
        ];
    }

    /** @return list<array{type: string, id: int}> */
    private function terminations(Payload $p, string $key): array
    {
        $raw = $p->raw($key);
        if (!\is_array($raw) || [] === $raw) {
            $p->error($key, 'Escolha ao menos uma terminação nesta ponta.');

            return [];
        }
        if (\count($raw) > 48) {
            $p->error($key, 'Máximo de 48 terminações por ponta.');

            return [];
        }
        $out = [];
        foreach ($raw as $t) {
            $type = \is_array($t) ? ($t['type'] ?? 'port') : 'port';
            $tid = \is_array($t) ? ($t['id'] ?? null) : $t;
            if (!\in_array($type, ['port', 'circuit'], true) || !is_numeric($tid) || (int) $tid < 1) {
                $p->error($key, 'Terminação inválida.');

                return [];
            }
            $out[] = ['type' => (string) $type, 'id' => (int) $tid];
        }

        return $out;
    }

    /**
     * @param list<array{type: string, id: int}> $a
     * @param list<array{type: string, id: int}> $b
     */
    private function validate(array $a, array $b, ?int $cableId): void
    {
        $sideA = $this->side($a, 'a_terminations', $cableId);
        $sideB = $this->side($b, 'b_terminations', $cableId);
        $overlap = array_intersect(array_map(static fn (array $t): string => $t['type'].$t['id'], $a), array_map(static fn (array $t): string => $t['type'].$t['id'], $b));
        if ([] !== $overlap) {
            throw new ValidationException(['b_terminations' => 'A mesma porta não pode estar nas duas pontas.']);
        }
        if (!\in_array($sideB['kind'], DcimChoices::COMPATIBLE[$sideA['kind']] ?? [], true)) {
            throw new ValidationException(['b_terminations' => \sprintf('Não é possível ligar %s em %s.', self::kindLabel($sideA['kind']), self::kindLabel($sideB['kind']))]);
        }
    }

    /**
     * @param list<array{type: string, id: int}> $terms
     *
     * @return array{kind: string, parent: string}
     */
    private function side(array $terms, string $field, ?int $cableId): array
    {
        $portIds = array_map(static fn (array $t): int => $t['id'], array_filter($terms, static fn (array $t): bool => 'port' === $t['type']));
        $ctIds = array_map(static fn (array $t): int => $t['id'], array_filter($terms, static fn (array $t): bool => 'circuit' === $t['type']));
        if ([] !== $portIds && [] !== $ctIds) {
            throw new ValidationException([$field => 'Todas as terminações de uma ponta precisam ser do mesmo tipo.']);
        }
        if ([] !== $portIds) {
            $rows = $this->db->fetchAllAssociative(<<<'SQL'
                SELECT p.id, p.kind, p.type, p.name, p.device_id, p.mark_connected, d.name AS device, t.cable_id
                FROM dcim_port p JOIN dcim_device d ON d.id = p.device_id LEFT JOIN dcim_cable_termination t ON t.port_id = p.id
                WHERE p.id IN (?)
                SQL, [array_values($portIds)], [ArrayParameterType::INTEGER]);
            if (\count($rows) !== \count(array_unique($portIds))) {
                throw new ValidationException([$field => 'Porta não encontrada.']);
            }
            foreach ($rows as $r) {
                if (null !== $r['cable_id'] && (int) $r['cable_id'] !== $cableId) {
                    throw new ValidationException([$field => \sprintf('%s %s já tem cabo.', $r['device'], $r['name'])]);
                }
                if ((bool) $r['mark_connected']) {
                    throw new ValidationException([$field => \sprintf('%s %s está marcada como conectada (sem cabo documentado).', $r['device'], $r['name'])]);
                }
                if ('interface' === $r['kind'] && \in_array($r['type'], DcimChoices::NON_CONNECTABLE, true)) {
                    throw new ValidationException([$field => \sprintf('%s %s é virtual/wireless e não recebe cabo.', $r['device'], $r['name'])]);
                }
            }
            if (\count(array_unique(array_column($rows, 'kind'))) > 1) {
                throw new ValidationException([$field => 'Todas as terminações de uma ponta precisam ser do mesmo tipo de porta.']);
            }
            if (\count(array_unique(array_column($rows, 'device_id'))) > 1) {
                throw new ValidationException([$field => 'Todas as terminações de uma ponta precisam ser do mesmo equipamento.']);
            }

            return ['kind' => (string) $rows[0]['kind'], 'parent' => 'device'.$rows[0]['device_id']];
        }
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT ct.id, ct.circuit_id, ct.provider_network, t.cable_id FROM circuit_termination ct LEFT JOIN dcim_cable_termination t ON t.circuit_termination_id = ct.id
            WHERE ct.id IN (?)
            SQL, [array_values($ctIds)], [ArrayParameterType::INTEGER]);
        if ([] === $rows || \count($rows) !== \count(array_unique($ctIds))) {
            throw new ValidationException([$field => 'Terminação de circuito não encontrada.']);
        }
        foreach ($rows as $r) {
            if (null !== $r['cable_id'] && (int) $r['cable_id'] !== $cableId) {
                throw new ValidationException([$field => 'A terminação do circuito já tem cabo.']);
            }
            if (null !== $r['provider_network']) {
                throw new ValidationException([$field => 'Terminação na rede da operadora não recebe cabo.']);
            }
        }
        if (\count(array_unique(array_column($rows, 'circuit_id'))) > 1) {
            throw new ValidationException([$field => 'Terminações de uma ponta precisam ser do mesmo circuito.']);
        }

        return ['kind' => 'circuit', 'parent' => 'circuit'.$rows[0]['circuit_id']];
    }

    private static function kindLabel(string $kind): string
    {
        return 'circuit' === $kind ? 'circuito' : mb_strtolower(DcimChoices::PORT_KINDS[$kind] ?? $kind);
    }
}
