<?php

declare(strict_types=1);

namespace App\Dcim\Service;

use App\Ui\Write\NotFoundException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Topologia da rede (ADR-0017 §2–3).
 *
 *  - graph(): nós (equipamentos, circuitos, rede da operadora, clusters e VMs) e arestas, em modo
 *      "logical"  — equipamento ativo ↔ equipamento ativo pelo destino do trace (patch panel/tomada/DIO colapsados);
 *      "physical" — cabo por cabo, com os passivos como nós.
 *  - pathToInternet(): BFS no grafo lógico a partir de um equipamento (o host da VM) até o circuito de operadora
 *    mais próximo; devolve os trechos em ordem, cada um com o trace completo para desenhar.
 */
final class TopologyService
{
    /** @var array<int, list<array<string, mixed>>> vizinhos lógicos por equipamento (cache da requisição) */
    private array $neighbors = [];

    public function __construct(private readonly Connection $db, private readonly CableTracer $tracer)
    {
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, links: list<array<string, mixed>>, mode: string}
     */
    public function graph(?int $branchId, string $mode = 'logical', bool $withVms = true): array
    {
        $devices = Num::rows($this->db->fetchAllAssociative(<<<'SQL'
            SELECT d.id, d.name, d.status, r.name AS role, r.color, b.id AS branch_id, b.name AS branch, l.name AS location, k.name AS rack, d.position::float8 AS position,
                   m.name || ' ' || t.model AS device_type, d.cluster_id, a.uuid AS asset_uuid,
                   (SELECT host(ip.address) FROM ipam_ip_address ip WHERE ip.id = d.primary_ip_id) AS primary_ip,
                   NOT EXISTS (SELECT 1 FROM dcim_port p WHERE p.device_id = d.id AND p.kind NOT IN ('front', 'rear')) AS passive,
                   (SELECT count(*) FROM dcim_port p JOIN dcim_cable_termination ct ON ct.port_id = p.id WHERE p.device_id = d.id) AS connected,
                   (SELECT count(*) FROM dcim_port p WHERE p.device_id = d.id) AS ports,
                   coalesce((SELECT x.uuid FROM attachment x WHERE x.owner_type = 'device' AND x.owner_id = d.id AND x.kind = 'photo' ORDER BY x.created_at LIMIT 1),
                            (SELECT x.uuid FROM attachment x WHERE x.owner_type = 'device_type' AND x.owner_id = d.device_type_id AND x.kind = 'photo' ORDER BY x.created_at LIMIT 1)) AS photo
            FROM dcim_device d
            JOIN dcim_device_role r ON r.id = d.role_id
            JOIN dcim_device_type t ON t.id = d.device_type_id JOIN manufacturer m ON m.id = t.manufacturer_id
            JOIN branch b ON b.id = d.branch_id
            LEFT JOIN location l ON l.id = d.location_id LEFT JOIN dcim_rack k ON k.id = d.rack_id LEFT JOIN asset a ON a.id = d.asset_id
            WHERE CAST(:branch AS int) IS NULL OR d.branch_id = :branch
            SQL, ['branch' => $branchId]), ['position'], ['connected', 'ports']);
        $nodes = [];
        foreach ($devices as $d) {
            if ('logical' === $mode && $d['passive']) {
                continue; // patch panel, tomada e DIO viram detalhe da aresta
            }
            $nodes['d'.$d['id']] = ['id' => 'd'.$d['id'], 'kind' => 'device'] + $d;
        }
        $deviceIds = array_map(static fn (array $d): int => (int) $d['id'], $devices);
        $links = 'logical' === $mode ? $this->logicalLinks($deviceIds, $nodes) : $this->physicalLinks($deviceIds, $nodes);

        if ($withVms) {
            $clusters = $this->db->fetchAllAssociative(<<<'SQL'
                SELECT c.id, c.name, t.name AS type, c.status, c.management_url, (SELECT count(*) FROM virt_machine v WHERE v.cluster_id = c.id) AS vms
                FROM virt_cluster c JOIN virt_cluster_type t ON t.id = c.type_id
                WHERE CAST(:branch AS int) IS NULL OR c.branch_id = :branch OR EXISTS (SELECT 1 FROM dcim_device d WHERE d.cluster_id = c.id AND d.branch_id = :branch)
                SQL, ['branch' => $branchId]);
            foreach ($clusters as $c) {
                $nodes['k'.$c['id']] = ['id' => 'k'.$c['id'], 'kind' => 'cluster', 'cluster_id' => (int) $c['id'], 'name' => $c['name'], 'type' => $c['type'],
                    'status' => $c['status'], 'vms' => (int) $c['vms'], 'management_url' => $c['management_url']];
            }
            foreach ($devices as $d) {
                if (null !== $d['cluster_id'] && isset($nodes['k'.$d['cluster_id']], $nodes['d'.$d['id']])) {
                    $links[] = ['source' => 'd'.$d['id'], 'target' => 'k'.$d['cluster_id'], 'kind' => 'member', 'label' => 'host do cluster'];
                }
            }
            $clusterIds = array_map(static fn (array $c): int => (int) $c['id'], $clusters);
            if ([] !== $clusterIds) {
                foreach (Num::rows($this->db->fetchAllAssociative(<<<'SQL'
                    SELECT v.id, v.name, v.status, v.platform, v.vcpus::float8 AS vcpus, v.memory_mb, v.disk_gb, v.cluster_id, v.device_id,
                           (SELECT host(ip.address) FROM ipam_ip_address ip WHERE ip.id = v.primary_ip_id) AS primary_ip
                    FROM virt_machine v WHERE v.cluster_id IN (?)
                    SQL, [$clusterIds], [ArrayParameterType::INTEGER]), ['vcpus']) as $v) {
                    $nodes['v'.$v['id']] = ['id' => 'v'.$v['id'], 'kind' => 'vm', 'vm_id' => (int) $v['id']] + $v;
                    $host = null !== $v['device_id'] && isset($nodes['d'.$v['device_id']]) ? 'd'.$v['device_id'] : 'k'.$v['cluster_id'];
                    $links[] = ['source' => 'v'.$v['id'], 'target' => $host, 'kind' => 'virtual', 'label' => 'roda em'];
                }
            }
        }

        return ['nodes' => array_values($nodes), 'links' => $links, 'mode' => $mode];
    }

    /**
     * Caminho do equipamento até a internet (circuito de operadora mais próximo), por BFS no grafo lógico.
     *
     * @return array<string, mixed>
     */
    public function pathToInternet(int $deviceId): array
    {
        $this->neighbors = []; // cache só vale dentro de uma busca (o serviço é compartilhado)
        $start = $this->db->fetchAssociative('SELECT d.id, d.name FROM dcim_device d WHERE d.id = ?', [$deviceId]) ?: throw new NotFoundException('Equipamento não encontrado.');
        $queue = [$deviceId];
        $prev = [$deviceId => null];
        $explored = 0;
        while ([] !== $queue && $explored < 500) {
            $current = array_shift($queue);
            ++$explored;
            foreach ($this->neighborsOf($current) as $edge) {
                if ('circuit' === $edge['to_kind']) {
                    $segments = [$edge];
                    $at = $current;
                    while (null !== $prev[$at]) {
                        array_unshift($segments, $prev[$at]);
                        $at = (int) $prev[$at]['from_device_id'];
                    }

                    return ['found' => true, 'start' => $start, 'segments' => array_map(fn (array $s): array => $this->segment($s), $segments), 'reason' => null];
                }
                $next = (int) $edge['to_device_id'];
                if ('device' === $edge['to_kind'] && $edge['complete'] && !\array_key_exists($next, $prev)) {
                    $prev[$next] = $edge;
                    $queue[] = $next;
                }
            }
        }
        $cabled = (int) $this->db->fetchOne(
            "SELECT count(*) FROM dcim_port p JOIN dcim_cable_termination t ON t.port_id = p.id WHERE p.device_id = ? AND p.kind = 'interface'", [$deviceId],
        );

        return ['found' => false, 'start' => $start, 'segments' => [], 'reached' => \count($prev) - 1,
            'reason' => 0 === $cabled ? 'no_cable' : 'no_route'];
    }

    /** @param array<string, mixed> $edge @return array<string, mixed> */
    private function segment(array $edge): array
    {
        return $edge + ['trace' => $this->tracer->trace('port', (int) $edge['from_port_id'])];
    }

    /**
     * Vizinhos lógicos: para cada interface com cabo, o fim do caminho.
     *
     * @return list<array<string, mixed>>
     */
    private function neighborsOf(int $deviceId): array
    {
        if (isset($this->neighbors[$deviceId])) {
            return $this->neighbors[$deviceId];
        }
        $out = [];
        $ports = $this->db->fetchAllAssociative(
            "SELECT p.id, p.name FROM dcim_port p JOIN dcim_cable_termination t ON t.port_id = p.id WHERE p.device_id = ? AND p.kind = 'interface' ORDER BY p.name",
            [$deviceId],
        );
        foreach ($ports as $port) {
            $t = $this->tracer->trace('port', (int) $port['id']);
            $end = $t['ends'][1][0] ?? null;
            if (null === $end) {
                continue;
            }
            $isCircuit = 'circuit' === ($end['node'] ?? null);
            $out[] = [
                'from_device_id' => $deviceId, 'from_port_id' => (int) $port['id'], 'from_port' => $port['name'],
                'to_kind' => $isCircuit ? 'circuit' : 'device', 'to_device_id' => $isCircuit ? null : $end['device_id'],
                'to_device' => $isCircuit ? null : $end['device'], 'to_port' => $end['name'], 'to_port_id' => $isCircuit ? null : $end['id'],
                'circuit_id' => $isCircuit ? $end['circuit_id'] : null, 'provider' => $isCircuit ? $end['provider'] : null, 'cid' => $isCircuit ? $end['cid'] : null,
                'provider_network' => $isCircuit ? $end['provider_network'] : null,
                'complete' => $t['complete'], 'active' => $t['active'], 'cables' => $t['cables'], 'length_m' => $t['length_m'],
            ];
        }

        return $this->neighbors[$deviceId] = $out;
    }

    /**
     * @param list<int>                           $deviceIds
     * @param array<string, array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function logicalLinks(array $deviceIds, array &$nodes): array
    {
        if ([] === $deviceIds) {
            return [];
        }
        $links = [];
        $seen = [];
        $ports = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT p.id, p.device_id, p.name, p.kind FROM dcim_port p JOIN dcim_cable_termination t ON t.port_id = p.id
            WHERE p.device_id IN (?) AND p.kind IN ('interface', 'console', 'console_server', 'power_port', 'power_outlet')
            SQL, [$deviceIds], [ArrayParameterType::INTEGER]);
        foreach ($ports as $port) {
            $t = $this->tracer->trace('port', (int) $port['id']);
            $end = $t['ends'][1][0] ?? null;
            if (null === $end || (int) ($end['id'] ?? 0) === (int) $port['id']) {
                continue;
            }
            $source = 'd'.$port['device_id'];
            if ('circuit' === ($end['node'] ?? null)) {
                $target = 'c'.$end['circuit_id'];
                $this->addCircuitNode($nodes, $end, $links);
            } else {
                $target = 'd'.$end['device_id'];
                if (!isset($nodes[$target])) {
                    // equipamento de outra filial (ou passivo onde o caminho termina incompleto)
                    $nodes[$target] = ['id' => $target, 'kind' => 'device', 'device_id' => $end['device_id'], 'name' => $end['device'],
                        'role' => $end['role'], 'color' => $end['color'], 'branch' => $end['branch'], 'location' => $end['location'], 'rack' => $end['rack'],
                        'external' => true, 'passive' => \in_array($end['kind'], ['front', 'rear'], true)];
                }
            }
            $key = $source < $target ? "$source|$target|{$port['id']}" : "$target|$source|{$end['id']}";
            $pair = $source < $target ? [$port['id'], $end['id']] : [$end['id'], $port['id']];
            $dedupe = $source.$target.min($pair).max($pair);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $seen[$target.$source.min($pair).max($pair)] = true;
            $cables = array_values(array_filter($t['hops'], static fn (array $h): bool => 'cable' === $h['t']));
            $color = null;
            $via = [];
            foreach ($t['hops'] as $h) {
                if ('cable' === $h['t'] && null === $color && null !== $h['cable']['color']) {
                    $color = $h['cable']['color'];
                }
                if ('nodes' === $h['t'] && 'port' === ($h['nodes'][0]['node'] ?? null) && \in_array($h['nodes'][0]['kind'], ['front', 'rear'], true)) {
                    $via[(string) $h['nodes'][0]['device']] = true;
                }
            }
            $links[] = [
                'source' => $source, 'target' => $target, 'kind' => str_starts_with((string) $port['kind'], 'power') ? 'power' : (str_starts_with((string) $port['kind'], 'console') ? 'console' : 'data'),
                'from_port' => $port['name'], 'from_port_id' => (int) $port['id'], 'to_port' => $end['name'], 'color' => $color,
                'cables' => \count($cables), 'labels' => implode(' · ', array_filter(array_map(static fn (array $h): ?string => $h['cable']['label'], $cables))),
                'via' => array_keys($via), 'length_m' => $t['length_m'], 'active' => $t['active'], 'complete' => $t['complete'], 'key' => $key,
                'type' => $cables[0]['cable']['type_label'] ?? null,
            ];
        }

        return $links;
    }

    /**
     * @param list<int>                           $deviceIds
     * @param array<string, array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function physicalLinks(array $deviceIds, array &$nodes): array
    {
        if ([] === $deviceIds) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT c.id, c.label, c.color, c.status, c.type, c.length::float8 AS length, c.length_unit, t.cable_end, p.device_id, p.name AS port, p.kind, t.circuit_termination_id
            FROM dcim_cable c JOIN dcim_cable_termination t ON t.cable_id = c.id LEFT JOIN dcim_port p ON p.id = t.port_id
            WHERE c.id IN (SELECT t2.cable_id FROM dcim_cable_termination t2 JOIN dcim_port p2 ON p2.id = t2.port_id WHERE p2.device_id IN (?))
            ORDER BY c.id, t.id
            SQL, [$deviceIds], [ArrayParameterType::INTEGER]);
        $cables = [];
        foreach ($rows as $r) {
            $cables[(int) $r['id']]['cable'] = $r;
            $cables[(int) $r['id']][(string) $r['cable_end']][] = $r;
        }
        $links = [];
        foreach ($cables as $id => $c) {
            $a = $c['A'][0] ?? null;
            $b = $c['B'][0] ?? null;
            if (null === $a || null === $b) {
                continue;
            }
            $ends = [];
            foreach ([$a, $b] as $e) {
                if (null !== $e['circuit_termination_id']) {
                    $ct = $this->tracer->trace('circuit', (int) $e['circuit_termination_id'])['origin'];
                    $this->addCircuitNode($nodes, $ct, $links);
                    $ends[] = 'c'.$ct['circuit_id'];
                } else {
                    $ends[] = 'd'.$e['device_id'];
                }
            }
            foreach ($ends as $n) {
                if (!isset($nodes[$n])) {
                    $nodes[$n] = ['id' => $n, 'kind' => 'device', 'name' => 'Equipamento #'.substr($n, 1), 'external' => true];
                }
            }
            $cab = $c['cable'];
            $links[] = ['source' => $ends[0], 'target' => $ends[1], 'kind' => 'power' === $cab['type'] ? 'power' : 'data', 'cable_id' => $id, 'labels' => $cab['label'],
                'from_port' => implode(', ', array_column($c['A'], 'port')), 'to_port' => implode(', ', array_column($c['B'], 'port')), 'color' => $cab['color'],
                'active' => 'connected' === $cab['status'], 'complete' => true, 'cables' => 1, 'type' => DcimChoices::CABLE_TYPES[(string) $cab['type']] ?? null,
                'length_m' => null === $cab['length'] ? null : (float) $cab['length'] * (['cm' => 0.01, 'km' => 1000.0, 'ft' => 0.3048][(string) $cab['length_unit']] ?? 1.0), 'via' => []];
        }

        return $links;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, mixed>                $ct    nó de terminação de circuito do tracer
     * @param list<array<string, mixed>>          $links
     */
    private function addCircuitNode(array &$nodes, array $ct, array &$links): void
    {
        $id = 'c'.$ct['circuit_id'];
        if (isset($nodes[$id])) {
            return;
        }
        $nodes[$id] = ['id' => $id, 'kind' => 'circuit', 'circuit_id' => $ct['circuit_id'], 'name' => $ct['provider'].' · '.$ct['cid'], 'provider' => $ct['provider'],
            'cid' => $ct['cid'], 'type' => $ct['circuit_type'], 'commit_rate_kbps' => $ct['commit_rate_kbps'], 'status' => $ct['circuit_status']];
        $net = $this->db->fetchOne('SELECT provider_network FROM circuit_termination WHERE circuit_id = ? AND provider_network IS NOT NULL LIMIT 1', [$ct['circuit_id']]);
        if (\is_string($net)) {
            $cloud = 'n'.md5($ct['provider'].$net);
            $nodes[$cloud] ??= ['id' => $cloud, 'kind' => 'cloud', 'name' => $net, 'provider' => $ct['provider']];
            $links[] = ['source' => $id, 'target' => $cloud, 'kind' => 'provider', 'label' => 'rede da operadora'];
        }
    }
}
