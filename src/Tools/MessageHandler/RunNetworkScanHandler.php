<?php

declare(strict_types=1);

namespace App\Tools\MessageHandler;

use App\Shared\Security\SecretBox;
use App\Tools\Entity\NetworkScan;
use App\Tools\Message\RunNetworkScan;
use App\Tools\Scanner\Capabilities;
use App\Tools\Scanner\DeviceClassifier;
use App\Tools\Scanner\MacVendors;
use App\Tools\Scanner\NmapHostParser;
use App\Tools\Scanner\NmapRunner;
use App\Tools\Scanner\ScanProfile;
use App\Tools\Scanner\ScanTarget;
use App\Tools\Scanner\SnmpArpCollector;
use App\Tools\Service\ScanCorrelator;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Executa a varredura no worker `bianchi-scan-worker` (CAP_NET_RAW, sem root).
 *
 * 1. (opcional) tabela ARP do gateway via SNMP → MACs de outras VLANs;
 * 2. nmap em fluxo: cada host vira linha na hora (a tela mostra ao vivo);
 * 3. hosts só vistos no ARP do gateway (não responderam) entram como "arp";
 * 4. correlação com o inventário (MAC → IP → nome) e filial.
 *
 * Nunca lança exceção para o Messenger: falha vira status `failed` (assim a mensagem,
 * que carrega a comunidade SNMP cifrada, não vai para a fila de falhas no banco).
 */
#[AsMessageHandler]
final readonly class RunNetworkScanHandler
{
    /** Fases do nmap em português (o que aparece na barra de progresso). */
    private const array PHASES = [
        'ARP Ping Scan' => 'Descobrindo dispositivos (ARP)',
        'Ping Scan' => 'Descobrindo dispositivos (ping)',
        'Parallel DNS resolution of 1 host.' => 'Resolvendo nomes (DNS)',
        'SYN Stealth Scan' => 'Varrendo portas TCP',
        'Connect Scan' => 'Varrendo portas TCP',
        'UDP Scan' => 'Varrendo portas UDP',
        'Service scan' => 'Identificando serviços e versões',
        'NSE' => 'Coletando detalhes (scripts NSE)',
        'Script Pre-Scan' => 'Preparando scripts',
        'Script Post-Scan' => 'Finalizando scripts',
        'Traceroute' => 'Traceroute',
    ];

    public function __construct(
        private Connection $db,
        private NmapRunner $nmap,
        private NmapHostParser $parser,
        private DeviceClassifier $classifier,
        private MacVendors $vendors,
        private SnmpArpCollector $snmp,
        private ScanCorrelator $correlator,
        private SecretBox $box,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunNetworkScan $message): void
    {
        $scan = $this->db->fetchAssociative('SELECT id, target, profile, status FROM network_scan WHERE id = ?', [$message->scanId]);
        if (false === $scan || NetworkScan::QUEUED !== $scan['status']) {
            return;
        }
        $id = (int) $scan['id'];
        $privileged = Capabilities::canRawSocket();
        $warnings = [];
        $this->db->executeStatement(
            "UPDATE network_scan SET status = 'running', started_at = now(), phase = 'Iniciando', options = options || ?::jsonb WHERE id = ?",
            [json_encode(['privileged' => $privileged, 'nmap' => $this->nmap->version()]), $id],
        );

        try {
            if (!$this->nmap->available()) {
                throw new \RuntimeException('nmap não está instalado no servidor (apt install nmap).');
            }
            $target = ScanTarget::parse((string) $scan['target'], (string) $scan['profile']);
            if (!$privileged) {
                $warnings[] = 'Worker sem CAP_NET_RAW: varredura TCP connect, sem detecção de SO e sem MAC por ARP. Veja docs/tools/scanner-de-rede.md.';
            }

            // 1. ARP do gateway (SNMP)
            $community = null === $message->encryptedCommunity ? null : $this->box->decrypt($message->encryptedCommunity);
            $arp = [];
            if (null !== $message->snmpGateway && null !== $community) {
                $this->phase($id, 'Lendo a tabela ARP do gateway via SNMP', null);
                try {
                    $arp = $this->snmp->collect($message->snmpGateway, $community, $target);
                } catch (\Throwable $e) {
                    $warnings[] = 'SNMP no gateway: '.$e->getMessage();
                }
            }

            // 2. nmap
            $seen = [];
            $lastProgress = 0.0;
            $result = $this->nmap->run(
                ScanProfile::args((string) $scan['profile'], $privileged, $community),
                $target->cidr(),
                function (\SimpleXMLElement $host) use ($id, $arp, &$seen): void {
                    $h = $this->parser->parse($host);
                    if (null === $h) {
                        return;
                    }
                    if (null === $h['mac'] && isset($arp[$h['ip']])) {
                        $h['mac'] = $arp[$h['ip']];
                        $h['mac_source'] = 'snmp';
                    }
                    $seen[$h['ip']] = true;
                    $this->save($id, $h);
                },
                function (string $task, float $pct) use ($id, &$lastProgress): void {
                    if ($pct < 0 || microtime(true) - $lastProgress >= 1.0) {
                        $lastProgress = microtime(true);
                        $this->phase($id, self::PHASES[$task] ?? $task, $pct < 0 ? null : (int) min(99, round($pct)));
                    }
                },
                fn (): bool => NetworkScan::CANCELLED === $this->db->fetchOne('SELECT status FROM network_scan WHERE id = ?', [$id]),
                'full' === $scan['profile'] ? 3 * 3600 : 3600,
            );

            // 3. vistos só pelo gateway
            foreach ($arp as $ip => $mac) {
                if (!isset($seen[$ip])) {
                    $this->save($id, [
                        'ip' => $ip, 'state' => 'arp', 'mac' => $mac, 'mac_source' => 'snmp', 'vendor' => null, 'hostname' => null,
                        'netbios_name' => null, 'domain' => null, 'os' => null, 'os_accuracy' => null, 'latency_ms' => null,
                        'ports' => [], 'details' => ['reason' => 'tabela ARP do gateway (não respondeu ao scan)'], 'hints' => ['types' => [], 'vendors' => []],
                    ]);
                }
            }

            // 4. inventário
            $this->correlator->correlate($id);

            if ($result['stopped']) {
                $status = NetworkScan::CANCELLED === $this->db->fetchOne('SELECT status FROM network_scan WHERE id = ?', [$id]) ? NetworkScan::CANCELLED : NetworkScan::FAILED;
                $this->finish($id, $status, NetworkScan::FAILED === $status ? 'Tempo máximo de varredura excedido.' : null, $warnings);

                return;
            }
            if (0 !== $result['exit']) {
                throw new \RuntimeException('nmap terminou com código '.$result['exit'].': '.mb_substr($result['stderr'], 0, 1000));
            }
            $this->finish($id, NetworkScan::DONE, null, $warnings);
        } catch (\Throwable $e) {
            $this->logger->error('Network scan failed', ['scan_id' => $id, 'error' => $e->getMessage()]);
            $this->finish($id, NetworkScan::FAILED, $e->getMessage(), $warnings);
        }
    }

    /** @param array<string, mixed> $h */
    private function save(int $scanId, array $h): void
    {
        $h['vendor'] ??= $this->vendors->lookup($h['mac']);
        $type = $this->classifier->classify($h);
        unset($h['details']['mac_from']);
        $this->db->executeStatement(<<<'SQL'
            INSERT INTO network_scan_host (scan_id, ip, mac, mac_source, vendor, hostname, netbios_name, domain, os, os_accuracy,
                                           device_type, state, latency_ms, ports, details, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now())
            ON CONFLICT (scan_id, ip) DO UPDATE SET
                mac = coalesce(EXCLUDED.mac, network_scan_host.mac), mac_source = coalesce(EXCLUDED.mac_source, network_scan_host.mac_source),
                vendor = coalesce(EXCLUDED.vendor, network_scan_host.vendor), hostname = coalesce(EXCLUDED.hostname, network_scan_host.hostname),
                netbios_name = coalesce(EXCLUDED.netbios_name, network_scan_host.netbios_name), os = coalesce(EXCLUDED.os, network_scan_host.os),
                device_type = EXCLUDED.device_type, ports = EXCLUDED.ports, details = EXCLUDED.details
            SQL, [
            $scanId, $h['ip'], $h['mac'], $h['mac_source'], null === $h['vendor'] ? null : mb_substr((string) $h['vendor'], 0, 255),
            $h['hostname'], $h['netbios_name'], $h['domain'], $h['os'], $h['os_accuracy'], $type, $h['state'], $h['latency_ms'],
            json_encode($h['ports'], \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
            json_encode((object) $h['details'], \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        $this->db->executeStatement("UPDATE network_scan SET hosts_up = (SELECT count(*) FROM network_scan_host WHERE scan_id = ? AND state = 'up') WHERE id = ?", [$scanId, $scanId]);
    }

    /** @param int|null $progress null = mantém (nmap só anunciou a fase) */
    private function phase(int $id, string $phase, ?int $progress): void
    {
        $this->db->executeStatement(
            'UPDATE network_scan SET phase = ?, progress = CASE WHEN ?::int IS NULL THEN progress ELSE least(99, ?::int) END WHERE id = ? AND status = ?',
            [mb_substr($phase, 0, 128), $progress, $progress, $id, NetworkScan::RUNNING],
        );
    }

    /** @param list<string> $warnings */
    private function finish(int $id, string $status, ?string $error, array $warnings): void
    {
        $this->db->executeStatement(
            "UPDATE network_scan SET status = ?, error = ?, finished_at = now(), progress = CASE WHEN ? = 'done' THEN 100 ELSE progress END,
                    phase = NULL, options = options || ?::jsonb
             WHERE id = ?",
            [$status, $error, $status, json_encode(['warnings' => $warnings], \JSON_UNESCAPED_UNICODE), $id],
        );
    }
}
