<?php

declare(strict_types=1);

namespace App\Tools\Service;

use App\Shared\Security\SecretBox;
use App\Tools\Scanner\ScanProfile;
use App\Tools\Scanner\ScanTarget;
use App\Tools\Scanner\SnmpArpCollector;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Varreduras agendadas (ADR-0013). O timer systemd `bianchi-scan-scheduler.timer` roda
 * `app:scan:tick` a cada minuto: cada agendamento vencido vira uma varredura na fila "scan"
 * (sem sobreposição: se a anterior ainda roda, pula esta janela).
 */
final readonly class ScheduleService
{
    /** Quais mudanças geram alerta, por padrão. */
    public const array ALERT_DEFAULTS = [
        'new_device' => true,
        'mac_changed' => true,
        'ip_conflict' => true,
        'new_ports' => false,
        'device_gone' => false,
    ];

    public const int MAX_SCHEDULES = 50;

    public function __construct(
        private Connection $db,
        private ScanService $scans,
        private SecretBox $box,
        private SnmpArpCollector $snmp,
        private LoggerInterface $logger,
        private string $timezone = 'America/Sao_Paulo',
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map($this->cast(...), $this->db->fetchAllAssociative(<<<'SQL'
            SELECT sc.*,
                   ls.id AS last_scan_id, ls.status AS last_status, ls.hosts_up AS last_hosts, ls.error AS last_error, ls.finished_at AS last_finished_at,
                   (SELECT count(*) FROM network_alert al WHERE al.schedule_id = sc.id AND al.acknowledged_at IS NULL) AS open_alerts
            FROM scan_schedule sc
            LEFT JOIN LATERAL (SELECT * FROM network_scan s WHERE s.schedule_id = sc.id ORDER BY s.id DESC LIMIT 1) ls ON true
            ORDER BY sc.name
            SQL));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, string $actor): int
    {
        if ((int) $this->db->fetchOne('SELECT count(*) FROM scan_schedule') >= self::MAX_SCHEDULES) {
            throw new ValidationException(['name' => 'Limite de '.self::MAX_SCHEDULES.' agendamentos atingido.']);
        }
        $v = $this->validate($data, null);

        return (int) $this->db->fetchOne(<<<'SQL'
            INSERT INTO scan_schedule (name, target, profile, frequency, interval_hours, run_at, weekdays, snmp_gateway, snmp_community, alerts, enabled, next_run_at, created_by, created_at, updated_at)
            VALUES (:name, :target, :profile, :frequency, :interval_hours, :run_at, :weekdays, :snmp_gateway, :snmp_community, :alerts, :enabled, :next_run_at, :actor, now(), now())
            RETURNING id
            SQL, $v + ['actor' => $actor]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $current = $this->db->fetchAssociative('SELECT * FROM scan_schedule WHERE id = ?', [$id]) ?: throw new NotFoundException('Agendamento não encontrado.');
        $v = $this->validate($data, $current);
        $this->db->executeStatement(<<<'SQL'
            UPDATE scan_schedule SET name = :name, target = :target, profile = :profile, frequency = :frequency, interval_hours = :interval_hours,
                   run_at = :run_at, weekdays = :weekdays, snmp_gateway = :snmp_gateway, snmp_community = :snmp_community, alerts = :alerts,
                   enabled = :enabled, next_run_at = :next_run_at, updated_at = now()
            WHERE id = :id
            SQL, $v + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM scan_schedule WHERE id = ?', [$id])) {
            throw new NotFoundException('Agendamento não encontrado.');
        }
    }

    /** "Executar agora" (fora da janela; não altera o próximo horário). */
    public function runNow(int $id, string $actor): int
    {
        $sc = $this->db->fetchAssociative('SELECT * FROM scan_schedule WHERE id = ?', [$id]) ?: throw new NotFoundException('Agendamento não encontrado.');
        if (false !== $this->db->fetchOne("SELECT 1 FROM network_scan WHERE schedule_id = ? AND status IN ('queued', 'running')", [$id])) {
            throw new ValidationException(['schedule' => 'Já existe uma varredura deste agendamento em andamento.']);
        }

        return $this->launch($sc, 'agendamento "'.$sc['name'].'" (manual: '.$actor.')');
    }

    /**
     * Dispara os agendamentos vencidos. Chamado a cada minuto pelo timer.
     *
     * @return list<array{schedule: string, result: string}>
     */
    public function tick(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $log = [];
        $due = $this->db->fetchAllAssociative('SELECT * FROM scan_schedule WHERE enabled AND next_run_at <= ? ORDER BY next_run_at', [$now->format(\DATE_ATOM)]);
        foreach ($due as $sc) {
            $next = $this->nextRun($sc['frequency'], null === $sc['interval_hours'] ? null : (int) $sc['interval_hours'], $sc['run_at'], json_decode((string) $sc['weekdays'], true) ?: [], $now);
            // reserva a janela antes de enfileirar: dois ticks simultâneos não disparam duas vezes
            $claimed = $this->db->executeStatement('UPDATE scan_schedule SET next_run_at = ?, last_run_at = ? WHERE id = ? AND next_run_at = ?', [
                $next->format(\DATE_ATOM), $now->format(\DATE_ATOM), (int) $sc['id'], $sc['next_run_at'],
            ]);
            if (0 === (int) $claimed) {
                continue;
            }
            if (false !== $this->db->fetchOne("SELECT 1 FROM network_scan WHERE schedule_id = ? AND status IN ('queued', 'running')", [(int) $sc['id']])) {
                $log[] = ['schedule' => (string) $sc['name'], 'result' => 'pulado: execução anterior ainda em andamento'];
                continue;
            }
            try {
                $scanId = $this->launch($sc, 'agendamento "'.$sc['name'].'"');
                $log[] = ['schedule' => (string) $sc['name'], 'result' => 'varredura #'.$scanId];
            } catch (\Throwable $e) {
                $this->logger->warning('Scheduled scan not started', ['schedule' => $sc['name'], 'error' => $e->getMessage()]);
                $log[] = ['schedule' => (string) $sc['name'], 'result' => 'erro: '.$e->getMessage()];
            }
        }

        return $log;
    }

    /**
     * Próxima execução estritamente depois de $from, no fuso local.
     *
     * @param list<int> $weekdays ISO-8601 (1 = segunda … 7 = domingo)
     */
    public function nextRun(string $frequency, ?int $intervalHours, ?string $runAt, array $weekdays, \DateTimeImmutable $from): \DateTimeImmutable
    {
        $tz = new \DateTimeZone($this->timezone);
        $from = $from->setTimezone($tz);
        if ('interval' === $frequency) {
            // alinhado ao minuto cheio
            return $from->setTime((int) $from->format('H'), (int) $from->format('i'))->modify('+'.max(1, (int) $intervalHours).' hours');
        }
        [$h, $m] = array_map('intval', explode(':', (string) $runAt));
        $days = [] === $weekdays ? [1, 2, 3, 4, 5, 6, 7] : $weekdays;
        for ($i = 0; $i <= 7; ++$i) {
            $candidate = $from->modify("+{$i} days")->setTime($h, $m);
            if ($candidate > $from && \in_array((int) $candidate->format('N'), $days, true)) {
                return $candidate;
            }
        }

        return $from->modify('+1 day')->setTime($h, $m); // inalcançável
    }

    /** @param array<string, mixed> $sc */
    private function launch(array $sc, string $requestedBy): int
    {
        $community = null === $sc['snmp_community'] ? null : $this->box->decrypt((string) $sc['snmp_community']);

        return $this->scans->enqueue((string) $sc['target'], (string) $sc['profile'], $sc['snmp_gateway'], $community, mb_substr($requestedBy, 0, 64), (int) $sc['id']);
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $current
     *
     * @return array<string, mixed> parâmetros do INSERT/UPDATE
     */
    private function validate(array $data, ?array $current): array
    {
        $p = new Payload($data);
        $name = $p->string('name', 100, required: true);
        $profile = $p->enum('profile', ScanProfile::ALL, ScanProfile::DISCOVERY);
        $targetIn = $p->string('target', 43, required: true);
        $frequency = $p->enum('frequency', ['interval', 'daily'], 'daily');
        $interval = 'interval' === $frequency ? $p->int('interval_hours', 1, 168, required: true) : null;
        $runAt = 'daily' === $frequency ? $p->string('run_at', 5, required: true) : null;
        if (null !== $runAt && 1 !== preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $runAt)) {
            $p->error('run_at', 'Horário no formato HH:MM.');
        }
        $weekdays = $p->raw('weekdays') ?? [1, 2, 3, 4, 5, 6, 7];
        if (!\is_array($weekdays) || [] === $weekdays || [] !== array_diff($weekdays, [1, 2, 3, 4, 5, 6, 7])) {
            $p->error('weekdays', 'Escolha ao menos um dia da semana.');
            $weekdays = [];
        }
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        sort($weekdays);
        $target = null;
        if (null !== $targetIn && null !== $profile) {
            try {
                $target = ScanTarget::parse($targetIn, $profile);
            } catch (\InvalidArgumentException $e) {
                $p->error('target', $e->getMessage());
            }
        }
        $gateway = $p->string('snmp_gateway', 15);
        $community = $p->string('snmp_community', 64);
        $storedCommunity = null;
        if (null !== $gateway) {
            if (false === filter_var($gateway, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                $p->error('snmp_gateway', 'IP do gateway inválido.');
            } elseif (!$this->snmp->available()) {
                $p->error('snmp_gateway', 'Extensão php-snmp não instalada no servidor.');
            } elseif (null !== $community) {
                $storedCommunity = $this->box->encrypt($community);
            } elseif (null !== ($current['snmp_community'] ?? null) && $gateway === $current['snmp_gateway']) {
                $storedCommunity = $current['snmp_community']; // em branco na edição = mantém
            } else {
                $p->error('snmp_community', 'Informe a comunidade SNMP (somente leitura).');
            }
        }
        $alertsIn = $p->raw('alerts');
        $alerts = self::ALERT_DEFAULTS;
        foreach (\is_array($alertsIn) ? $alertsIn : [] as $k => $v) {
            if (\array_key_exists((string) $k, $alerts)) {
                $alerts[(string) $k] = (bool) $v;
            }
        }
        $enabled = $p->bool('enabled', true);
        $p->check();
        \assert(null !== $target && null !== $frequency);

        return [
            'name' => $name, 'target' => $target->cidr(), 'profile' => $profile, 'frequency' => $frequency,
            'interval_hours' => $interval, 'run_at' => $runAt, 'weekdays' => json_encode($weekdays),
            'snmp_gateway' => $gateway, 'snmp_community' => null === $gateway ? null : $storedCommunity,
            'alerts' => json_encode($alerts), 'enabled' => $enabled ? 'true' : 'false',
            'next_run_at' => $enabled ? $this->nextRun($frequency, $interval, $runAt, $weekdays, new \DateTimeImmutable())->format(\DATE_ATOM) : null,
        ];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function cast(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'target' => $r['target'],
            'profile' => $r['profile'],
            'frequency' => $r['frequency'],
            'interval_hours' => null === $r['interval_hours'] ? null : (int) $r['interval_hours'],
            'run_at' => $r['run_at'],
            'weekdays' => json_decode((string) $r['weekdays'], true) ?: [],
            'snmp_gateway' => $r['snmp_gateway'],
            'has_community' => null !== $r['snmp_community'],
            'alerts' => array_merge(self::ALERT_DEFAULTS, json_decode((string) $r['alerts'], true) ?: []),
            'enabled' => (bool) $r['enabled'],
            'next_run_at' => $r['next_run_at'],
            'last_run_at' => $r['last_run_at'],
            'last_scan' => null === $r['last_scan_id'] ? null : [
                'id' => (int) $r['last_scan_id'], 'status' => $r['last_status'], 'hosts_up' => (int) $r['last_hosts'], 'error' => $r['last_error'], 'finished_at' => $r['last_finished_at'],
            ],
            'open_alerts' => (int) $r['open_alerts'],
            'created_by' => $r['created_by'],
        ];
    }
}
