<?php

declare(strict_types=1);

namespace App\Tools\Alert;

use App\Tools\Scanner\MacVendors;
use App\Tools\Service\ScheduleService;
use Doctrine\DBAL\Connection;

/**
 * Analisa uma varredura concluída e gera alertas (ADR-0013).
 *
 * - **Conflito de IP** (qualquer varredura): o arp-scan viu 2+ MACs respondendo pelo mesmo IP.
 * - **Mudanças** (só varreduras agendadas), comparando com a varredura anterior do mesmo agendamento:
 *   dispositivo novo sem cadastro, ativo que sumiu, MAC que mudou num IP, portas novas.
 *   A primeira execução vira "linha de base".
 */
final readonly class ChangeDetector
{
    /** Portas que, ao aparecerem abertas, sobem a severidade de "portas novas". */
    private const array RISKY_PORTS = [21, 23, 69, 135, 139, 445, 1433, 3306, 3389, 5432, 5800, 5900, 5985, 6379, 9200, 11211, 27017];

    public function __construct(private Connection $db, private AlertService $alerts)
    {
    }

    /** @return list<int> ids dos alertas **novos** (para notificar) */
    public function analyze(int $scanId): array
    {
        $scan = $this->db->fetchAssociative(
            'SELECT s.id, s.schedule_id, s.branch_id, s.target, s.profile, sc.name AS schedule_name, sc.alerts, sc.snmp_gateway
             FROM network_scan s LEFT JOIN scan_schedule sc ON sc.id = s.schedule_id WHERE s.id = ?',
            [$scanId],
        );
        if (false === $scan) {
            return [];
        }
        $scheduleId = null === $scan['schedule_id'] ? null : (int) $scan['schedule_id'];
        $branchId = null === $scan['branch_id'] ? null : (int) $scan['branch_id'];
        $flags = ScheduleService::ALERT_DEFAULTS;
        if (null !== $scheduleId) {
            $flags = array_merge($flags, array_intersect_key(json_decode((string) $scan['alerts'], true) ?: [], $flags));
        }
        $new = [];
        $raise = function (string $kind, string $sev, string $key, string $title, array $detail, ?string $ip, ?string $mac, ?int $assetId) use (&$new, $scanId, $scheduleId, $branchId): void {
            $r = $this->alerts->raise($kind, $sev, $key, $title, $detail + ['target' => null], $ip, $mac, $scanId, $scheduleId, $branchId, $assetId);
            if ($r['new']) {
                $new[] = $r['id'];
            }
        };
        $current = $this->hosts($scanId);

        // ---- conflito de IP (arp-scan)
        if ($flags['ip_conflict']) {
            foreach ($current as $h) {
                $macs = $h['details']['arp_conflict'] ?? null;
                if (!\is_array($macs) || \count($macs) < 2) {
                    continue;
                }
                $list = array_column($macs, 'mac');
                sort($list);
                $raise('ip_conflict', 'critical', $h['ip'].'|'.implode(',', $list),
                    \sprintf('Conflito de IP: %s respondido por %d MACs (%s)', $h['ip'], \count($macs), implode(', ', array_map(static fn (array $m): string => $m['mac'].(null !== ($m['vendor'] ?? null) ? ' '.$m['vendor'] : ''), $macs))),
                    ['macs' => $macs, 'target' => $scan['target']], $h['ip'], null, $h['asset_id']);
            }
        }
        if (null === $scheduleId) {
            return $new;
        }

        // ---- mudanças em relação à execução anterior do mesmo agendamento
        $prevId = $this->db->fetchOne("SELECT id FROM network_scan WHERE schedule_id = ? AND id < ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$scheduleId, $scanId]);
        $name = (string) $scan['schedule_name'];
        if (false === $prevId) {
            $unknown = \count(array_filter($current, static fn (array $h): bool => null === $h['asset_id']));
            $raise('baseline', 'info', $scheduleId.'|'.$scanId, \sprintf('Linha de base de "%s" (%s): %d dispositivos, %d sem cadastro', $name, $scan['target'], \count($current), $unknown),
                ['devices' => \count($current), 'unknown' => $unknown, 'target' => $scan['target']], null, null, null);

            return $new;
        }
        $prevProfile = $this->db->fetchOne('SELECT profile FROM network_scan WHERE id = ?', [(int) $prevId]);
        $previous = $this->hosts((int) $prevId);
        $prevByIp = $prevByMac = $prevAssets = [];
        foreach ($previous as $h) {
            $prevByIp[$h['ip']] = $h;
            if (null !== $h['mac']) {
                $prevByMac[$h['mac']] = $h;
            }
            if (null !== $h['asset_id']) {
                $prevAssets[$h['asset_id']] = $h;
            }
        }
        $curAssets = [];
        foreach ($current as $h) {
            if (null !== $h['asset_id']) {
                $curAssets[$h['asset_id']] = true;
            }
        }

        foreach ($current as $h) {
            $old = $prevByIp[$h['ip']] ?? null;
            $known = null !== $h['mac'] ? isset($prevByMac[$h['mac']]) : null !== $old;
            $macChanged = null !== $old && null !== $old['mac'] && null !== $h['mac'] && $old['mac'] !== $h['mac']
                && !(null !== $h['asset_id'] && $h['asset_id'] === $old['asset_id'])
                && !MacVendors::isLocal((string) MacVendors::hex($h['mac']));

            // dispositivo novo sem cadastro (MAC trocado num IP conhecido vira só "MAC mudou")
            if ($flags['new_device'] && !$known && null === $h['asset_id'] && !($macChanged && $flags['mac_changed'])) {
                $what = array_filter([$h['vendor'], $h['netbios_name'] ?? $h['hostname'], $h['os']]);
                $raise('new_device', 'warning', $scheduleId.'|'.($h['mac'] ?? 'ip:'.$h['ip']),
                    \sprintf('Dispositivo novo sem cadastro: %s%s', $h['ip'], [] === $what ? '' : ' ('.implode(', ', $what).')'),
                    ['vendor' => $h['vendor'], 'hostname' => $h['hostname'], 'netbios' => $h['netbios_name'], 'os' => $h['os'], 'device_type' => $h['device_type'], 'target' => $scan['target']],
                    $h['ip'], $h['mac'], null);
            }

            // MAC mudou no mesmo IP (troca de equipamento, clonagem ou ARP spoofing)
            if ($flags['mac_changed'] && $macChanged) {
                \assert(null !== $old);
                $gateway = $h['ip'] === $scan['snmp_gateway'] || 1 === preg_match('/\.(1|254)$/', $h['ip']);
                $raise('mac_changed', $gateway ? 'critical' : 'warning', $scheduleId.'|'.$h['ip'].'|'.$h['mac'],
                    \sprintf('%s%s mudou de MAC: %s → %s%s', $gateway ? 'Gateway provável ' : '', $h['ip'], $old['mac'], $h['mac'], null !== $h['vendor'] ? ' ('.$h['vendor'].')' : ''),
                    ['old_mac' => $old['mac'], 'old_vendor' => $old['vendor'], 'new_vendor' => $h['vendor'], 'old_asset' => $old['asset_name'], 'gateway' => $gateway, 'target' => $scan['target']],
                    $h['ip'], $h['mac'], $h['asset_id'] ?? $old['asset_id']);
            }

            // portas novas (mesmo dispositivo, mesmo perfil)
            if ($flags['new_ports'] && null !== $old && $prevProfile === $scan['profile'] && $old['mac'] === $h['mac']) {
                $added = array_values(array_diff($h['ports'], $old['ports']));
                if ([] !== $added) {
                    sort($added);
                    $risky = array_filter($added, static fn (string $p): bool => \in_array((int) $p, self::RISKY_PORTS, true));
                    $raise('new_ports', [] === $risky ? 'info' : 'warning', $scheduleId.'|'.$h['ip'].'|'.implode(',', $added),
                        \sprintf('Portas novas em %s%s: %s', $h['ip'], null !== $h['asset_name'] ? ' ('.$h['asset_name'].')' : '', implode(', ', $added)),
                        ['ports' => $added, 'risky' => array_values($risky), 'target' => $scan['target']], $h['ip'], $h['mac'], $h['asset_id']);
                }
            }
        }

        // ativo do inventário que respondia e sumiu
        if ($flags['device_gone']) {
            foreach ($prevAssets as $assetId => $h) {
                if (!isset($curAssets[$assetId])) {
                    $raise('device_gone', 'info', $scheduleId.'|'.$assetId,
                        \sprintf('Ativo não respondeu: %s (%s)', $h['asset_name'] ?? '?', $h['ip']),
                        ['last_ip' => $h['ip'], 'target' => $scan['target']], $h['ip'], $h['mac'], (int) $assetId);
                }
            }
        }

        return $new;
    }

    /**
     * @return list<array{ip: string, mac: string|null, vendor: string|null, hostname: string|null, netbios_name: string|null, os: string|null,
     *                    device_type: string, asset_id: int|null, asset_name: string|null, ports: list<string>, details: array<string, mixed>}>
     */
    private function hosts(int $scanId): array
    {
        return array_map(static fn (array $h): array => [
            'ip' => (string) $h['ip'], 'mac' => $h['mac'], 'vendor' => $h['vendor'], 'hostname' => $h['hostname'], 'netbios_name' => $h['netbios_name'],
            'os' => $h['os'], 'device_type' => (string) $h['device_type'], 'asset_id' => null === $h['asset_id'] ? null : (int) $h['asset_id'], 'asset_name' => $h['asset_name'],
            'ports' => array_map(static fn (array $p): string => $p['port'].'/'.$p['proto'], json_decode((string) $h['ports'], true) ?: []),
            'details' => json_decode((string) $h['details'], true) ?: [],
        ], $this->db->fetchAllAssociative(
            "SELECT h.*, a.name AS asset_name FROM network_scan_host h LEFT JOIN asset a ON a.id = h.asset_id WHERE h.scan_id = ? AND h.state IN ('up', 'arp') ORDER BY h.ip::inet",
            [$scanId],
        ));
    }
}
