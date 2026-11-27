<?php

declare(strict_types=1);

namespace App\Tools\Service;

use App\Shared\Security\SecretBox;
use App\Tools\Entity\NetworkScan;
use App\Tools\Message\RunNetworkScan;
use App\Tools\Scanner\ScanProfile;
use App\Tools\Scanner\ScanTarget;
use App\Tools\Scanner\SnmpArpCollector;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;

/** Criação, consulta, cancelamento e exportação de varreduras (API em ScannerController). */
final readonly class ScanService
{
    /** Fila máxima (evita empilhar varreduras por engano/abuso). */
    public const int MAX_PENDING = 5;

    public function __construct(
        private Connection $db,
        private MessageBusInterface $bus,
        private SecretBox $box,
        private SnmpArpCollector $snmp,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, string $actor): int
    {
        $p = new Payload($data);
        $profile = $p->enum('profile', ScanProfile::ALL, ScanProfile::STANDARD);
        $targetInput = $p->string('target', 43, required: true);
        $gateway = $p->string('snmp_gateway', 15);
        $community = $p->string('snmp_community', 64);
        $target = null;
        if (null !== $targetInput && null !== $profile) {
            try {
                $target = ScanTarget::parse($targetInput, $profile);
            } catch (\InvalidArgumentException $e) {
                $p->error('target', $e->getMessage());
            }
        }
        if (null !== $gateway) {
            if (false === filter_var($gateway, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                $p->error('snmp_gateway', 'IP do gateway inválido.');
            } elseif (null === $community) {
                $p->error('snmp_community', 'Informe a comunidade SNMP (somente leitura).');
            } elseif (!$this->snmp->available()) {
                $p->error('snmp_gateway', 'Extensão php-snmp não instalada no servidor.');
            }
        }
        $p->check();
        \assert(null !== $target && null !== $profile);

        return $this->enqueue($target->cidr(), $profile, $gateway, $community, $actor, null);
    }

    /**
     * Grava a varredura como "na fila" e despacha para o bianchi-scan-worker. A comunidade SNMP
     * segue cifrada na mensagem e não é gravada em network_scan.
     */
    public function enqueue(string $cidr, string $profile, ?string $gateway, #[\SensitiveParameter] ?string $community, string $actor, ?int $scheduleId): int
    {
        $pending = (int) $this->db->fetchOne("SELECT count(*) FROM network_scan WHERE status IN ('queued', 'running')");
        if ($pending >= self::MAX_PENDING) {
            throw new ValidationException(['target' => 'Já existem varreduras demais na fila. Aguarde terminarem.']);
        }
        $target = ScanTarget::parse($cidr, $profile);

        $id = (int) $this->db->fetchOne(<<<'SQL'
            INSERT INTO network_scan (target, profile, options, status, progress, hosts_up, requested_by, schedule_id, branch_id, created_at)
            VALUES (:target, :profile, :options, 'queued', 0, 0, :actor, :schedule, (
                SELECT b.id FROM branch b CROSS JOIN LATERAL jsonb_array_elements_text(b.networks) n(cidr)
                WHERE b.active AND (:target::cidr <<= n.cidr::cidr OR n.cidr::cidr <<= :target::cidr)
                ORDER BY masklen(n.cidr::cidr) DESC LIMIT 1
            ), now()) RETURNING id
            SQL, [
            'target' => $target->cidr(),
            'profile' => $profile,
            'options' => json_encode(['snmp_gateway' => $gateway, 'addresses' => $target->addresses()]),
            'actor' => $actor,
            'schedule' => $scheduleId,
        ]);
        $this->bus->dispatch(new RunNetworkScan($id, $gateway, null === $gateway || null === $community ? null : $this->box->encrypt($community)));

        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map(self::castScan(...), $this->db->fetchAllAssociative(<<<'SQL'
            SELECT s.*, b.name AS branch, sc.name AS schedule FROM network_scan s LEFT JOIN branch b ON b.id = s.branch_id
            LEFT JOIN scan_schedule sc ON sc.id = s.schedule_id
            ORDER BY s.id DESC LIMIT 30
            SQL));
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $scan = $this->db->fetchAssociative('SELECT s.*, b.name AS branch, sc.name AS schedule FROM network_scan s LEFT JOIN branch b ON b.id = s.branch_id LEFT JOIN scan_schedule sc ON sc.id = s.schedule_id WHERE s.id = ?', [$id])
            ?: throw new NotFoundException('Varredura não encontrada.');
        $hosts = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT h.id, h.ip, h.mac, h.mac_source, h.vendor, h.hostname, h.netbios_name, h.domain, h.os, h.os_accuracy,
                   h.device_type, h.state, h.latency_ms, h.ports, h.details, h.match_by,
                   a.uuid AS asset_uuid, a.name AS asset_name, t.code AS asset_type, a.inventory_number AS asset_number
            FROM network_scan_host h
            LEFT JOIN asset a ON a.id = h.asset_id
            LEFT JOIN asset_type t ON t.id = a.asset_type_id
            WHERE h.scan_id = ?
            ORDER BY h.ip::inet
            SQL, [$id]);

        return self::castScan($scan) + [
            'hosts' => array_map(static fn (array $h): array => ['id' => (int) $h['id'], 'ports' => json_decode((string) $h['ports'], true) ?: [],
                'details' => json_decode((string) $h['details'], true) ?: []] + $h, $hosts),
        ];
    }

    public function cancel(int $id): void
    {
        $n = $this->db->executeStatement("UPDATE network_scan SET status = 'cancelled', finished_at = CASE WHEN status = 'queued' THEN now() ELSE finished_at END WHERE id = ? AND status IN ('queued', 'running')", [$id]);
        if (0 === (int) $n && false === $this->db->fetchOne('SELECT 1 FROM network_scan WHERE id = ?', [$id])) {
            throw new NotFoundException('Varredura não encontrada.');
        }
    }

    public function delete(int $id): void
    {
        $status = $this->db->fetchOne('SELECT status FROM network_scan WHERE id = ?', [$id]);
        if (false === $status) {
            throw new NotFoundException('Varredura não encontrada.');
        }
        if (\in_array($status, [NetworkScan::QUEUED, NetworkScan::RUNNING], true)) {
            throw new ValidationException(['scan' => 'Cancele a varredura antes de excluir.']);
        }
        $this->db->executeStatement('DELETE FROM network_scan WHERE id = ?', [$id]);
    }

    public function csv(int $id): string
    {
        $d = $this->detail($id);
        $out = fopen('php://temp', 'w+');
        \assert(false !== $out);
        fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel abre acentos corretamente
        fputcsv($out, ['IP', 'MAC', 'Origem do MAC', 'Fabricante', 'Hostname', 'NetBIOS', 'Domínio', 'Sistema', 'Tipo', 'Estado', 'Latência (ms)', 'Portas abertas', 'Ativo no inventário', 'Patrimônio'], ';', '"', '');
        foreach ($d['hosts'] as $h) {
            $ports = implode(' ', array_map(static fn (array $p): string => $p['port'].'/'.$p['proto'].(isset($p['service']) ? '('.$p['service'].')' : ''), $h['ports']));
            fputcsv($out, [$h['ip'], $h['mac'], $h['mac_source'], $h['vendor'], $h['hostname'], $h['netbios_name'], $h['domain'], $h['os'], $h['device_type'], $h['state'], $h['latency_ms'], $ports, $h['asset_name'], $h['asset_number']], ';', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private static function castScan(array $s): array
    {
        $opt = json_decode((string) $s['options'], true) ?: [];

        return [
            'id' => (int) $s['id'],
            'target' => $s['target'],
            'profile' => $s['profile'],
            'status' => $s['status'],
            'phase' => $s['phase'],
            'progress' => (int) $s['progress'],
            'hosts_up' => (int) $s['hosts_up'],
            'requested_by' => $s['requested_by'],
            'branch_id' => null === $s['branch_id'] ? null : (int) $s['branch_id'],
            'branch' => $s['branch'] ?? null,
            'schedule_id' => null === ($s['schedule_id'] ?? null) ? null : (int) $s['schedule_id'],
            'schedule' => $s['schedule'] ?? null,
            'error' => $s['error'],
            'snmp_gateway' => $opt['snmp_gateway'] ?? null,
            'privileged' => $opt['privileged'] ?? null,
            'nmap' => $opt['nmap'] ?? null,
            'warnings' => $opt['warnings'] ?? [],
            'addresses' => $opt['addresses'] ?? null,
            'created_at' => $s['created_at'],
            'started_at' => $s['started_at'],
            'finished_at' => $s['finished_at'],
        ];
    }
}
