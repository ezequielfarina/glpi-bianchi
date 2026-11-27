<?php

declare(strict_types=1);

namespace App\Dcim\Service;

use App\Ui\Write\NotFoundException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Trace de cabo ponta a ponta (ADR-0016 §5).
 *
 * Porta de `CablePath.from_origin()` (netbox/dcim/models/cables.py):
 *  - a partir de um conjunto de terminações (mesmo tipo, mesmo equipamento), segue o cabo até a outra ponta;
 *  - porta frontal → porta traseira pelo `dcim_port_mapping`; quando a traseira tem várias posições
 *    (bandeja de DIO, tronco MPO), a posição vai para uma pilha e é desempilhada no outro lado do tronco,
 *    o que devolve a fibra certa;
 *  - terminação de circuito A ↔ Z (para em `provider_network` = nuvem da operadora);
 *  - termina em porta "ponta de caminho" (interface, console, energia) → `complete`;
 *  - `active` só se todos os cabos estiverem `connected`; `split` quando o caminho bifurca sem contexto
 *    de posição ou entra em laço.
 *
 * Diferenças: calculado sob demanda (sem a tabela CablePath materializada) e, partindo de uma porta de
 * passagem (patch panel, tomada, terminação de circuito), traça os dois sentidos e junta os trechos.
 *
 * Formato devolvido — lista linear de saltos para a interface desenhar:
 *   hops: [{t: 'nodes', nodes: [...]}, {t: 'cable', cable: {...}}, {t: 'nodes', ...}, ...]
 * Dois grupos `nodes` seguidos do mesmo equipamento = entrou pela frontal e saiu pela traseira.
 */
final class CableTracer
{
    private const int MAX_SEGMENTS = 64;

    /** @var array<int, array<string, mixed>> */
    private array $portCache = [];

    /** @var array<int, array<string, mixed>> */
    private array $circuitCache = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{origin: array<string, mixed>, hops: list<array<string, mixed>>, complete: bool, active: bool, split: bool,
     *               ends: list<list<array<string, mixed>>>, cables: int, length_m: float|null, bidirectional: bool}
     */
    public function trace(string $type, int $id): array
    {
        $origin = 'circuit' === $type ? $this->circuitNodes([$id]) : $this->portNodes([$id]);
        if ([] === $origin) {
            throw new NotFoundException('circuit' === $type ? 'Terminação de circuito não encontrada.' : 'Porta não encontrada.');
        }
        $kind = (string) $origin[0]['kind'];
        if (\in_array($kind, DcimChoices::ENDPOINT_KINDS, true)) {
            $a = $this->walk($origin, true);
            $hops = $a['hops'];
            $complete = $a['complete'];
            $active = $a['active'];
            $split = $a['split'];
            $bidirectional = false;
        } else {
            // porta de passagem: um sentido pelo cabo, o outro atravessando o mapeamento
            $a = $this->walk($origin, true);
            $b = $this->walk($origin, false);
            $hops = [...array_reverse($b['hops']), ...\array_slice($a['hops'], 1)];
            $complete = $a['complete'] && $b['complete'];
            $active = $a['active'] && $b['active'];
            $split = $a['split'] || $b['split'];
            $bidirectional = true;
        }

        $cables = array_values(array_filter($hops, static fn (array $h): bool => 'cable' === $h['t']));
        $length = null;
        foreach ($cables as $h) {
            /** @var array{length_m: float|null} $c */
            $c = $h['cable'];
            if (null !== $c['length_m']) {
                $length = ($length ?? 0.0) + $c['length_m'];
            }
        }
        $groups = array_values(array_filter($hops, static fn (array $h): bool => 'nodes' === $h['t']));
        /** @var list<array<string, mixed>> $first */
        $first = $groups[0]['nodes'] ?? [];
        /** @var list<array<string, mixed>> $last */
        $last = $groups[\count($groups) - 1]['nodes'] ?? [];

        return [
            'origin' => $origin[0],
            'hops' => $hops,
            'complete' => $complete,
            'active' => $active && [] !== $cables,
            'split' => $split,
            'ends' => [$first, $last],
            'cables' => \count($cables),
            'length_m' => null === $length ? null : round($length, 2),
            'bidirectional' => $bidirectional,
        ];
    }

    /**
     * Destino do caminho de uma porta ponta-de-caminho (o "Connection" do NetBox): a última porta quando o
     * caminho está completo, senão null.
     *
     * @return array{nodes: list<array<string, mixed>>, active: bool}|null
     */
    public function destination(int $portId): ?array
    {
        $origin = $this->portNodes([$portId]);
        if ([] === $origin) {
            return null;
        }
        $w = $this->walk($origin, true);
        if (!$w['complete']) {
            return null;
        }
        $last = $w['hops'][\count($w['hops']) - 1];

        /** @var list<array<string, mixed>> $nodes */
        $nodes = $last['nodes'] ?? [];

        return ['nodes' => $nodes, 'active' => $w['active']];
    }

    /**
     * @param list<array<string, mixed>> $origin
     *
     * @return array{hops: list<array<string, mixed>>, complete: bool, active: bool, split: bool}
     */
    private function walk(array $origin, bool $startWithCable): array
    {
        $hops = [['t' => 'nodes', 'nodes' => $origin]];
        $stack = [];
        $complete = false;
        $active = true;
        $split = false;
        $seenCables = [];
        $terms = $origin;
        $mode = $startWithCable ? 'cable' : 'pass';

        for ($segment = 0; $segment < self::MAX_SEGMENTS; ++$segment) {
            if ('pass' === $mode) {
                $step = $this->passThrough($terms, $stack);
                if ($step['split']) {
                    $split = true;
                }
                if ([] === $step['next']) {
                    break;
                }
                $hops[] = ['t' => 'nodes', 'nodes' => $step['next']];
                if ($step['final']) {
                    $complete = $step['complete'];
                    break;
                }
                $terms = $step['next'];
                $mode = 'cable';
                continue;
            }

            // Passo 2–7 do from_origin: cabo(s) das terminações e a ponta oposta
            $links = $this->linksOf($terms);
            if ([] === $links) {
                break; // ponta solta → caminho incompleto
            }
            if (\count($links) > 1) {
                $split = true; // terminações da mesma ponta em cabos diferentes
                break;
            }
            [$cableId, $end] = $links[0];
            if (isset($seenCables[$cableId])) {
                $split = true; // laço
                break;
            }
            $seenCables[$cableId] = true;
            $cable = $this->cable($cableId);
            $hops[] = ['t' => 'cable', 'cable' => $cable];
            if ('connected' !== $cable['status']) {
                $active = false;
            }
            $far = $this->farEnd($cableId, 'A' === $end ? 'B' : 'A');
            if ([] === $far) {
                break;
            }
            $hops[] = ['t' => 'nodes', 'nodes' => $far];
            if (\in_array($far[0]['kind'], DcimChoices::ENDPOINT_KINDS, true)) {
                $complete = true;
                break;
            }
            $terms = $far;
            $mode = 'pass';
        }

        return ['hops' => $hops, 'complete' => $complete, 'active' => $active, 'split' => $split];
    }

    /**
     * Passo 8 do from_origin: atravessa o equipamento de passagem (frontal↔traseira) ou o circuito (A↔Z).
     *
     * @param list<array<string, mixed>> $terms
     * @param list<list<int>>            $stack pilha de posições
     *
     * @return array{next: list<array<string, mixed>>, split: bool, final: bool, complete: bool}
     */
    private function passThrough(array $terms, array &$stack): array
    {
        $kind = (string) $terms[0]['kind'];
        $ids = array_map(static fn (array $t): int => (int) $t['id'], $terms);
        $none = ['next' => [], 'split' => false, 'final' => false, 'complete' => false];

        if ('front' === $kind) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT m.rear_port_id, m.rear_position, p.positions FROM dcim_port_mapping m JOIN dcim_port p ON p.id = m.rear_port_id
                 WHERE m.front_port_id IN (?) ORDER BY m.front_port_id, m.front_position',
                [$ids], [ArrayParameterType::INTEGER],
            );
            if ([] === $rows) {
                return $none;
            }
            $rearIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['rear_port_id'], $rows)));
            if (array_filter($rows, static fn (array $r): bool => (int) $r['positions'] > 1)) {
                $stack[] = array_map(static fn (array $r): int => (int) $r['rear_position'], $rows);
            }

            return ['next' => $this->portNodes($rearIds), 'split' => false, 'final' => false, 'complete' => false];
        }

        if ('rear' === $kind) {
            $multi = array_filter($terms, static fn (array $t): bool => (int) $t['positions'] > 1);
            if ([] !== $multi) {
                if ([] === $stack) {
                    // tronco multiposição sem saber de qual posição viemos: bifurca (NetBox marca split)
                    return ['next' => [], 'split' => true, 'final' => false, 'complete' => false];
                }
                $positions = array_pop($stack);
                $rows = $this->db->fetchAllAssociative(
                    'SELECT front_port_id FROM dcim_port_mapping WHERE rear_port_id IN (?) AND rear_position IN (?) ORDER BY rear_position',
                    [$ids, $positions], [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER],
                );
            } else {
                $rows = $this->db->fetchAllAssociative(
                    'SELECT front_port_id FROM dcim_port_mapping WHERE rear_port_id IN (?) ORDER BY rear_position',
                    [$ids], [ArrayParameterType::INTEGER],
                );
            }
            if ([] === $rows) {
                return $none;
            }
            $frontIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['front_port_id'], $rows)));

            return ['next' => $this->portNodes($frontIds), 'split' => false, 'final' => false, 'complete' => false];
        }

        if ('circuit' === $kind) {
            $peerIds = array_map('intval', $this->db->fetchFirstColumn(
                "SELECT p.id FROM circuit_termination t JOIN circuit_termination p ON p.circuit_id = t.circuit_id AND p.term_side <> t.term_side
                 WHERE t.id IN (?)",
                [$ids], [ArrayParameterType::INTEGER],
            ));
            if ([] === $peerIds) {
                return $none;
            }
            $peers = $this->circuitNodes($peerIds);
            if (array_filter($peers, static fn (array $p): bool => null !== $p['provider_network']) === $peers) {
                return ['next' => $peers, 'split' => false, 'final' => true, 'complete' => true]; // nuvem da operadora
            }
            if ([] === $this->linksOf($peers)) {
                return ['next' => $peers, 'split' => false, 'final' => true, 'complete' => false]; // lado Z sem cabo
            }

            return ['next' => $peers, 'split' => false, 'final' => false, 'complete' => false];
        }

        return $none;
    }

    /**
     * @param list<array<string, mixed>> $terms
     *
     * @return list<array{0: int, 1: string}> [cable_id, cable_end] distintos
     */
    private function linksOf(array $terms): array
    {
        $ids = array_map(static fn (array $t): int => (int) $t['id'], $terms);
        $column = 'circuit' === $terms[0]['kind'] ? 'circuit_termination_id' : 'port_id';
        $rows = $this->db->fetchAllAssociative(
            "SELECT DISTINCT cable_id, cable_end FROM dcim_cable_termination WHERE $column IN (?) ORDER BY cable_id",
            [$ids], [ArrayParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [(int) $r['cable_id'], (string) $r['cable_end']], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function farEnd(int $cableId, string $end): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT port_id, circuit_termination_id FROM dcim_cable_termination WHERE cable_id = ? AND cable_end = ? ORDER BY id',
            [$cableId, $end],
        );
        $ports = array_values(array_filter(array_map(static fn (array $r): ?int => null === $r['port_id'] ? null : (int) $r['port_id'], $rows)));
        $circuits = array_values(array_filter(array_map(static fn (array $r): ?int => null === $r['circuit_termination_id'] ? null : (int) $r['circuit_termination_id'], $rows)));

        return [] !== $ports ? $this->portNodes($ports) : $this->circuitNodes($circuits);
    }

    /** @return array<string, mixed> */
    private function cable(int $id): array
    {
        $c = $this->db->fetchAssociative('SELECT id, label, type, status, color, length, length_unit FROM dcim_cable WHERE id = ?', [$id]);
        if (false === $c) {
            throw new NotFoundException('Cabo não encontrado.');
        }
        $length = null === $c['length'] ? null : (float) $c['length'];
        $factor = ['m' => 1.0, 'cm' => 0.01, 'km' => 1000.0, 'ft' => 0.3048][(string) ($c['length_unit'] ?? 'm')] ?? 1.0;
        $c['length_m'] = null === $length ? null : $length * $factor;
        $c['type_label'] = DcimChoices::CABLE_TYPES[(string) $c['type']] ?? null;

        return $c;
    }

    /**
     * Portas com o contexto para desenhar (equipamento, função, rack/U, local, filial, ativo, IPs).
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function portNodes(array $ids): array
    {
        $missing = array_values(array_diff($ids, array_keys($this->portCache)));
        if ([] !== $missing) {
            $rows = $this->db->fetchAllAssociative(<<<'SQL'
                SELECT p.id, p.kind, p.name, p.label, p.type, p.positions, p.device_id, d.name AS device, r.name AS role, r.color,
                       d.rack_id, k.name AS rack, d.position, d.face, d.location_id, l.name AS location, d.branch_id, b.name AS branch,
                       a.uuid AS asset_uuid, a.name AS asset_name,
                       (SELECT string_agg(host(ip.address) || '/' || masklen(ip.address), ', ' ORDER BY ip.address)
                          FROM ipam_ip_address ip WHERE ip.port_id = p.id) AS ips
                FROM dcim_port p
                JOIN dcim_device d ON d.id = p.device_id
                JOIN dcim_device_role r ON r.id = d.role_id
                JOIN branch b ON b.id = d.branch_id
                LEFT JOIN dcim_rack k ON k.id = d.rack_id
                LEFT JOIN location l ON l.id = d.location_id
                LEFT JOIN asset a ON a.id = d.asset_id
                WHERE p.id IN (?)
                SQL, [$missing], [ArrayParameterType::INTEGER]);
            foreach ($rows as $r) {
                $r['node'] = 'port';
                $r['id'] = (int) $r['id'];
                $r['positions'] = (int) $r['positions'];
                $r['position'] = null === $r['position'] ? null : (float) $r['position'];
                $r['kind_label'] = DcimChoices::PORT_KINDS[(string) $r['kind']] ?? $r['kind'];
                $r['type_label'] = DcimChoices::PORT_TYPES[(string) $r['kind']][(string) $r['type']] ?? $r['type'];
                $this->portCache[$r['id']] = $r;
            }
        }

        return array_values(array_filter(array_map(fn (int $id): ?array => $this->portCache[$id] ?? null, $ids)));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function circuitNodes(array $ids): array
    {
        $missing = array_values(array_diff($ids, array_keys($this->circuitCache)));
        if ([] !== $missing) {
            $rows = $this->db->fetchAllAssociative(<<<'SQL'
                SELECT t.id, t.term_side, t.circuit_id, c.cid, c.status AS circuit_status, s.name AS provider, ty.name AS circuit_type,
                       c.commit_rate_kbps, t.provider_network, t.xconnect_id, t.pp_info, t.branch_id, b.name AS branch, t.location_id, l.name AS location
                FROM circuit_termination t
                JOIN circuit c ON c.id = t.circuit_id
                JOIN supplier s ON s.id = c.supplier_id
                JOIN circuit_type ty ON ty.id = c.type_id
                LEFT JOIN branch b ON b.id = t.branch_id
                LEFT JOIN location l ON l.id = t.location_id
                WHERE t.id IN (?)
                SQL, [$missing], [ArrayParameterType::INTEGER]);
            foreach ($rows as $r) {
                $r['node'] = 'circuit';
                $r['kind'] = 'circuit';
                $r['id'] = (int) $r['id'];
                $r['positions'] = 1;
                $r['name'] = 'Lado '.$r['term_side'];
                $this->circuitCache[$r['id']] = $r;
            }
        }

        return array_values(array_filter(array_map(fn (int $id): ?array => $this->circuitCache[$id] ?? null, $ids)));
    }
}
