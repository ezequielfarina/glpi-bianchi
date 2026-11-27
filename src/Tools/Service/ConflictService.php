<?php

declare(strict_types=1);

namespace App\Tools\Service;

use App\Tools\Scanner\MacVendors;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Ferramentas → Conflitos de IP/MAC (ADR-0013). Quatro fontes, da mais à menos conclusiva:
 *
 * 1. **IP em conflito (ARP)**: arp-scan viu 2+ MACs respondendo pelo mesmo IP — conflito real agora
 *    (ou VRRP/HSRP/proxy-ARP, que também respondem com outro MAC).
 * 2. **IP em uso × inventário**: a varredura viu o IP com o MAC A, mas um ativo inventariado há pouco
 *    diz que o IP é dele (MAC B) e o MAC B não apareceu em outro IP — IP fixo duplicado ou DHCP com reserva errada.
 * 3. **MAC duplicado no inventário**: o mesmo MAC físico em 2+ ativos — VM clonada sem novo MAC,
 *    cadastro duplicado (manual + agente), ou adaptador USB/dock que passou de um notebook para outro.
 * 4. **IP duplicado no inventário**: 2+ ativos inventariados nos últimos dias reportando o mesmo IP
 *    dentro das redes das filiais.
 *
 * MACs fixos de adaptadores virtuais (FortiClient, Npcap, AnyConnect, VMnet...) e portas virtuais são ignorados.
 */
final readonly class ConflictService
{
    public function __construct(private Connection $db, private MacVendors $vendors)
    {
    }

    /** @return array<string, mixed> */
    public function report(int $days = 7): array
    {
        $days = max(1, min(90, $days));

        return [
            'days' => $days,
            'arp' => $this->arpConflicts($days),
            'scan_vs_inventory' => $this->scanVsInventory($days),
            'mac_duplicates' => $this->macDuplicates(),
            'ip_duplicates' => $this->ipDuplicates($days),
            'last_scan_with_arp' => $this->db->fetchOne(
                "SELECT max(created_at) FROM network_scan WHERE status = 'done' AND (options->>'privileged')::boolean IS TRUE",
            ) ?: null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function arpConflicts(int $days): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT DISTINCT ON (h.ip) h.ip, h.details->'arp_conflict' AS macs, h.scan_id, s.target, s.created_at, b.name AS branch,
                   a.uuid AS asset_uuid, a.name AS asset_name
            FROM network_scan_host h
            JOIN network_scan s ON s.id = h.scan_id
            LEFT JOIN branch b ON b.id = s.branch_id
            LEFT JOIN asset a ON a.id = h.asset_id
            WHERE h.details->'arp_conflict' IS NOT NULL AND s.created_at > now() - make_interval(days => :d)
            ORDER BY h.ip, h.scan_id DESC
            SQL, ['d' => $days]);
        $out = [];
        foreach ($rows as $r) {
            $macs = json_decode((string) $r['macs'], true) ?: [];
            $owners = $this->owners(array_column($macs, 'mac'));
            $out[] = [
                'ip' => $r['ip'], 'scan_id' => (int) $r['scan_id'], 'target' => $r['target'], 'seen_at' => $r['created_at'], 'branch' => $r['branch'],
                'macs' => array_map(static fn (array $m): array => $m + ['assets' => $owners[$m['mac']] ?? []], $macs),
                // ainda em conflito na varredura mais recente da mesma rede?
                'current' => (int) $r['scan_id'] === (int) $this->db->fetchOne("SELECT max(id) FROM network_scan WHERE target = ? AND status = 'done'", [$r['target']]),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function scanVsInventory(int $days): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            WITH latest AS (
                SELECT DISTINCT ON (target) id, target, created_at FROM network_scan
                WHERE status = 'done' AND created_at > now() - make_interval(days => :d) ORDER BY target, id DESC
            ),
            seen AS (
                SELECT h.scan_id, h.ip, h.mac, h.vendor, h.hostname, h.netbios_name, l.target, l.created_at
                FROM network_scan_host h JOIN latest l ON l.id = h.scan_id
                WHERE h.mac IS NOT NULL AND h.mac_source IN ('arp', 'snmp')
            )
            SELECT s.ip, s.mac AS seen_mac, s.vendor AS seen_vendor, coalesce(s.netbios_name, s.hostname) AS seen_name, s.scan_id, s.target, s.created_at,
                   a.uuid AS asset_uuid, a.name AS asset_name, p.mac_address AS asset_mac, a.last_inventory_at
            FROM seen s
            JOIN ip_address i ON i.address = s.ip AND i.version = 4
            JOIN network_port p ON p.id = i.network_port_id AND NOT p.virtual
            JOIN asset a ON a.id = p.asset_id AND a.deleted_at IS NULL
            WHERE p.mac_address IS NOT NULL AND p.mac_address <> s.mac
              AND a.last_inventory_at > s.created_at - interval '2 days'
              -- o MAC do ativo não está no IP atual por outro motivo (o próprio ativo tem esse MAC em outra porta)
              AND NOT EXISTS (SELECT 1 FROM network_port p2 WHERE p2.asset_id = a.id AND p2.mac_address = s.mac)
              -- se o MAC do ativo apareceu em outro IP na mesma varredura, é só troca de DHCP
              AND NOT EXISTS (SELECT 1 FROM network_scan_host h2 WHERE h2.scan_id = s.scan_id AND h2.mac = p.mac_address AND h2.ip <> s.ip)
            ORDER BY s.ip::inet
            SQL, ['d' => $days]);
        $owners = $this->owners(array_values(array_unique(array_column($rows, 'seen_mac'))));

        return array_map(fn (array $r): array => [
            'ip' => $r['ip'], 'scan_id' => (int) $r['scan_id'], 'target' => $r['target'], 'seen_at' => $r['created_at'],
            'seen' => ['mac' => $r['seen_mac'], 'vendor' => $r['seen_vendor'] ?? $this->vendors->lookup($r['seen_mac']), 'name' => $r['seen_name'], 'assets' => $owners[$r['seen_mac']] ?? []],
            'inventory' => ['mac' => $r['asset_mac'], 'vendor' => $this->vendors->lookup($r['asset_mac']), 'asset_uuid' => $r['asset_uuid'], 'asset_name' => $r['asset_name'], 'last_inventory_at' => $r['last_inventory_at']],
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function macDuplicates(): array
    {
        $ignore = array_keys(MacVendors::SHARED_VIRTUAL);
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            WITH m AS (
                SELECT p.mac_address AS mac, a.id, a.uuid, a.name, t.code AS type, a.serial_number, a.last_inventory_at, p.name AS port, a.inventory_number
                FROM network_port p JOIN asset a ON a.id = p.asset_id AND a.deleted_at IS NULL LEFT JOIN asset_type t ON t.id = a.asset_type_id
                WHERE p.mac_address IS NOT NULL AND NOT p.virtual
                UNION
                SELECT lower(a.attributes->>'mac'), a.id, a.uuid, a.name, t.code, a.serial_number, a.last_inventory_at, NULL, a.inventory_number
                FROM asset a LEFT JOIN asset_type t ON t.id = a.asset_type_id
                WHERE a.deleted_at IS NULL AND a.attributes->>'mac' IS NOT NULL
            )
            SELECT mac, json_agg(json_build_object('uuid', uuid, 'name', name, 'type', type, 'serial', serial_number, 'port', port,
                                                   'inventory_number', inventory_number, 'last_inventory_at', last_inventory_at) ORDER BY last_inventory_at DESC NULLS LAST) AS assets
            FROM m
            WHERE mac NOT IN (:ignore) AND mac !~ '^(00:00:00:00:00:00|ff:ff:ff:ff:ff:ff)$' AND substr(mac, 2, 1) NOT IN ('2', '3', '6', '7', 'a', 'b', 'e', 'f')
            GROUP BY mac HAVING count(DISTINCT id) > 1
            ORDER BY count(DISTINCT id) DESC, mac
            LIMIT 200
            SQL, ['ignore' => $ignore], ['ignore' => ArrayParameterType::STRING]);

        return array_map(function (array $r): array {
            $assets = json_decode((string) $r['assets'], true) ?: [];
            $hv = MacVendors::HYPERVISOR[substr((string) $r['mac'], 0, 8)] ?? null;
            $hint = match (true) {
                null !== $hv => "MAC de VM ({$hv}): VM clonada/template sem gerar MAC novo.",
                \count(array_unique(array_filter(array_column($assets, 'serial')))) <= 1 && \count(array_filter(array_column($assets, 'serial'))) > 0 => 'Mesmo número de série: cadastro duplicado (ex.: manual + agente).',
                1 === preg_match('/usb|dock|thunderbolt|realtek usb|asix|dell.*(wd|da)/i', implode(' ', array_column($assets, 'port'))) => 'Adaptador USB/dock: o dispositivo passou de uma máquina para outra.',
                default => 'Verifique se é clonagem de MAC, troca de placa ou cadastro duplicado.',
            };

            return ['mac' => $r['mac'], 'vendor' => $this->vendors->lookup((string) $r['mac']), 'assets' => $assets, 'hint' => $hint];
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function ipDuplicates(int $days): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            WITH nets AS (SELECT DISTINCT n.cidr::cidr AS cidr FROM branch b CROSS JOIN LATERAL jsonb_array_elements_text(b.networks) n(cidr) WHERE b.active),
            ips AS (
                SELECT i.address AS ip, a.id, a.uuid, a.name, t.code AS type, p.mac_address AS mac, p.name AS port, a.last_inventory_at
                FROM ip_address i
                JOIN network_port p ON p.id = i.network_port_id AND NOT p.virtual
                JOIN asset a ON a.id = p.asset_id AND a.deleted_at IS NULL
                LEFT JOIN asset_type t ON t.id = a.asset_type_id
                WHERE i.version = 4 AND a.last_inventory_at > now() - make_interval(days => :d)
                  AND i.address !~ '^(127\.|169\.254\.|0\.)'
                  AND (NOT EXISTS (SELECT 1 FROM nets) OR EXISTS (SELECT 1 FROM nets WHERE i.address::inet <<= nets.cidr))
            )
            SELECT ip, json_agg(json_build_object('uuid', uuid, 'name', name, 'type', type, 'mac', mac, 'port', port, 'last_inventory_at', last_inventory_at)
                                ORDER BY last_inventory_at DESC) AS assets,
                   max(last_inventory_at) - min(last_inventory_at) AS spread
            FROM ips GROUP BY ip HAVING count(DISTINCT id) > 1
            ORDER BY ip::inet LIMIT 200
            SQL, ['d' => $days]);

        return array_map(static function (array $r): array {
            $assets = json_decode((string) $r['assets'], true) ?: [];
            // inventários com horas de diferença: provável DHCP que trocou de dono; próximos: conflito provável
            $ts = array_map(static fn (array $a): int => (int) strtotime((string) $a['last_inventory_at']), $assets);
            $close = max($ts) - min($ts) < 6 * 3600;

            return ['ip' => $r['ip'], 'assets' => $assets, 'likely' => $close,
                'hint' => $close ? 'Inventariados com menos de 6 h de diferença: conflito provável (IP fixo repetido?).' : 'Inventários distantes no tempo: provavelmente o DHCP reaproveitou o IP.'];
        }, $rows);
    }

    /**
     * @param list<string> $macs
     *
     * @return array<string, list<array{uuid: string, name: string}>>
     */
    private function owners(array $macs): array
    {
        if ([] === $macs) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAllAssociative(<<<'SQL'
            SELECT DISTINCT x.mac, a.uuid, a.name FROM (
                SELECT mac_address AS mac, asset_id FROM network_port WHERE mac_address IN (:macs)
                UNION SELECT lower(attributes->>'mac'), id FROM asset WHERE lower(attributes->>'mac') IN (:macs)
            ) x JOIN asset a ON a.id = x.asset_id AND a.deleted_at IS NULL
            SQL, ['macs' => $macs], ['macs' => ArrayParameterType::STRING]) as $r) {
            $out[$r['mac']][] = ['uuid' => $r['uuid'], 'name' => $r['name']];
        }

        return $out;
    }
}
