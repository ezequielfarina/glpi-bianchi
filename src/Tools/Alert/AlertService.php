<?php

declare(strict_types=1);

namespace App\Tools\Alert;

use App\Tools\Entity\NetworkAlert;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Registro e consulta de alertas de rede (ADR-0013).
 *
 * Deduplicação por `fingerprint` = SHA-256(tipo + chave): enquanto houver um alerta aberto com a
 * mesma impressão, a repetição só soma `occurrences` e atualiza `last_seen_at` (e não notifica de novo).
 * Depois de reconhecido, a mesma condição abre um alerta novo.
 */
final readonly class AlertService
{
    public const array LABEL = [
        'new_device' => 'Dispositivo novo',
        'device_gone' => 'Ativo sumiu',
        'mac_changed' => 'MAC mudou',
        'new_ports' => 'Portas novas',
        'ip_conflict' => 'Conflito de IP',
        'scan_failed' => 'Varredura falhou',
        'baseline' => 'Linha de base',
    ];

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array{id: int, new: bool}
     */
    public function raise(string $kind, string $severity, string $key, string $title, array $detail = [], ?string $ip = null, ?string $mac = null,
        ?int $scanId = null, ?int $scheduleId = null, ?int $branchId = null, ?int $assetId = null): array
    {
        \assert(\in_array($kind, NetworkAlert::KINDS, true) && \in_array($severity, NetworkAlert::SEVERITIES, true));
        $fp = hash('sha256', $kind.'|'.$key);
        $json = json_encode((object) $detail, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        $title = mb_substr($title, 0, 255);
        $id = $this->db->fetchOne(<<<'SQL'
            UPDATE network_alert SET occurrences = occurrences + 1, last_seen_at = now(), title = :title, detail = :detail,
                   scan_id = coalesce(:scan, scan_id), severity = :severity
            WHERE id = (SELECT id FROM network_alert WHERE fingerprint = :fp AND acknowledged_at IS NULL ORDER BY id DESC LIMIT 1)
            RETURNING id
            SQL, ['title' => $title, 'detail' => $json, 'scan' => $scanId, 'severity' => $severity, 'fp' => $fp]);
        if (false !== $id) {
            return ['id' => (int) $id, 'new' => false];
        }
        $id = (int) $this->db->fetchOne(<<<'SQL'
            INSERT INTO network_alert (kind, severity, fingerprint, title, detail, ip, mac, scan_id, schedule_id, branch_id, asset_id, occurrences, created_at, last_seen_at)
            VALUES (:kind, :severity, :fp, :title, :detail, :ip, :mac, :scan, :schedule, :branch, :asset, 1, now(), now())
            RETURNING id
            SQL, ['kind' => $kind, 'severity' => $severity, 'fp' => $fp, 'title' => $title, 'detail' => $json, 'ip' => $ip, 'mac' => $mac,
            'scan' => $scanId, 'schedule' => $scheduleId, 'branch' => $branchId, 'asset' => $assetId]);

        return ['id' => $id, 'new' => true];
    }

    /**
     * @param array<string, mixed> $q status (open|acknowledged|all), kind, severity, schedule_id
     *
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function list(array $q): array
    {
        $where = ['1 = 1'];
        $params = [];
        $status = (string) ($q['status'] ?? 'open');
        if ('open' === $status) {
            $where[] = 'al.acknowledged_at IS NULL';
        } elseif ('acknowledged' === $status) {
            $where[] = 'al.acknowledged_at IS NOT NULL';
        }
        if (\in_array($q['kind'] ?? null, NetworkAlert::KINDS, true)) {
            $where[] = 'al.kind = :kind';
            $params['kind'] = $q['kind'];
        }
        if (\in_array($q['severity'] ?? null, NetworkAlert::SEVERITIES, true)) {
            $where[] = 'al.severity = :severity';
            $params['severity'] = $q['severity'];
        }
        if (isset($q['schedule_id']) && ctype_digit((string) $q['schedule_id'])) {
            $where[] = 'al.schedule_id = :schedule';
            $params['schedule'] = (int) $q['schedule_id'];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT al.*, sc.name AS schedule_name, b.name AS branch, a.uuid AS asset_uuid, a.name AS asset_name, s.target AS scan_target
             FROM network_alert al
             LEFT JOIN scan_schedule sc ON sc.id = al.schedule_id
             LEFT JOIN branch b ON b.id = al.branch_id
             LEFT JOIN asset a ON a.id = al.asset_id
             LEFT JOIN network_scan s ON s.id = al.scan_id
             WHERE '.implode(' AND ', $where).'
             ORDER BY al.acknowledged_at IS NULL DESC, CASE al.severity WHEN \'critical\' THEN 0 WHEN \'warning\' THEN 1 ELSE 2 END, al.last_seen_at DESC, al.id DESC
             LIMIT 500',
            $params,
        );

        return ['items' => array_map(self::cast(...), $rows), 'counts' => $this->counts()];
    }

    /** @return array<string, int> abertos por severidade + total */
    public function counts(): array
    {
        $c = ['critical' => 0, 'warning' => 0, 'info' => 0, 'open' => 0];
        foreach ($this->db->fetchAllKeyValue('SELECT severity, count(*) FROM network_alert WHERE acknowledged_at IS NULL GROUP BY severity') as $sev => $n) {
            $c[(string) $sev] = (int) $n;
            $c['open'] += (int) $n;
        }

        return $c;
    }

    /**
     * @param list<int>|null $ids null = todos os abertos (respeitando o filtro de tipo)
     */
    public function acknowledge(?array $ids, string $actor, ?string $kind = null): int
    {
        if (null === $ids) {
            return (int) $this->db->executeStatement(
                'UPDATE network_alert SET acknowledged_at = now(), acknowledged_by = :actor WHERE acknowledged_at IS NULL AND (:kind::text IS NULL OR kind = :kind)',
                ['actor' => $actor, 'kind' => \in_array($kind, NetworkAlert::KINDS, true) ? $kind : null],
            );
        }
        $ids = array_values(array_filter($ids, 'is_int'));
        if ([] === $ids || \count($ids) > 1000) {
            throw new ValidationException(['ids' => 'Selecione de 1 a 1000 alertas.']);
        }

        return (int) $this->db->executeStatement(
            'UPDATE network_alert SET acknowledged_at = now(), acknowledged_by = :actor WHERE acknowledged_at IS NULL AND id IN (:ids)',
            ['actor' => $actor, 'ids' => $ids], ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /** Remove alertas reconhecidos há mais de N dias (chamado pelo tick do agendador). */
    public function purge(int $days = 180): int
    {
        return (int) $this->db->executeStatement('DELETE FROM network_alert WHERE acknowledged_at < now() - make_interval(days => :d)', ['d' => $days]);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    public static function cast(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'kind' => $r['kind'],
            'kind_label' => self::LABEL[$r['kind']] ?? $r['kind'],
            'severity' => $r['severity'],
            'title' => $r['title'],
            'detail' => json_decode((string) $r['detail'], true) ?: [],
            'ip' => $r['ip'],
            'mac' => $r['mac'],
            'occurrences' => (int) $r['occurrences'],
            'scan_id' => null === $r['scan_id'] ? null : (int) $r['scan_id'],
            'scan_target' => $r['scan_target'] ?? null,
            'schedule_id' => null === $r['schedule_id'] ? null : (int) $r['schedule_id'],
            'schedule' => $r['schedule_name'] ?? null,
            'branch' => $r['branch'] ?? null,
            'asset_uuid' => $r['asset_uuid'] ?? null,
            'asset_name' => $r['asset_name'] ?? null,
            'created_at' => $r['created_at'],
            'last_seen_at' => $r['last_seen_at'],
            'notified_at' => $r['notified_at'],
            'acknowledged_at' => $r['acknowledged_at'],
            'acknowledged_by' => $r['acknowledged_by'],
        ];
    }
}
