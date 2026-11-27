<?php

declare(strict_types=1);

namespace App\Audit\Service;

use Doctrine\DBAL\Connection;

/** Consulta da trilha de auditoria (ADR-0015) — tela Auditoria → Ações no sistema. */
final readonly class AuditQuery
{
    public const int PER_PAGE = 50;

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param array{q?: string|null, module?: string|null, actor?: string|null, entity_type?: string|null, entity_id?: string|null, from?: string|null, to?: string|null, page?: int} $f
     *
     * @return array<string, mixed>
     */
    public function list(array $f): array
    {
        $where = ['true'];
        $params = [];
        if (null !== ($f['q'] ?? null) && '' !== trim((string) $f['q'])) {
            $where[] = '(l.subject ILIKE :q OR l.actor ILIKE :q OR l.path ILIKE :q OR l.action ILIKE :q OR l.details::text ILIKE :q)';
            $params['q'] = '%'.trim((string) $f['q']).'%';
        }
        foreach (['module' => 'l.module', 'actor' => 'l.actor', 'entity_type' => 'l.entity_type', 'entity_id' => 'l.entity_id'] as $k => $col) {
            if (null !== ($f[$k] ?? null) && '' !== $f[$k]) {
                $where[] = "$col = :$k";
                $params[$k] = $f[$k];
            }
        }
        if (isset($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['from'])) {
            $where[] = "l.occurred_at >= CAST(:from AS date)::timestamp AT TIME ZONE 'America/Sao_Paulo'";
            $params['from'] = $f['from'];
        }
        if (isset($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['to'])) {
            $where[] = "l.occurred_at < (CAST(:to AS date) + 1)::timestamp AT TIME ZONE 'America/Sao_Paulo'";
            $params['to'] = $f['to'];
        }
        $w = implode(' AND ', $where);
        $page = max(1, $f['page'] ?? 1);
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = $this->db->fetchAllAssociative(
            "SELECT l.id, l.occurred_at, l.actor, l.ip, l.action, l.module, l.method, l.path, l.entity_type, l.entity_id, l.subject, l.status, l.details
             FROM audit_log l WHERE $w ORDER BY l.occurred_at DESC, l.id DESC LIMIT ".self::PER_PAGE." OFFSET $offset",
            $params,
        );
        $total = (int) $this->db->fetchOne("SELECT count(*) FROM audit_log l WHERE $w", $params);

        return [
            'items' => array_map(static function (array $r): array {
                [, $label] = AuditRecorder::describe((string) $r['action'], (string) $r['method']);
                $label = self::labelFor((string) $r['action']) ?? $label;

                return [
                    'id' => (int) $r['id'],
                    'occurred_at' => $r['occurred_at'],
                    'actor' => $r['actor'],
                    'ip' => $r['ip'],
                    'action' => $r['action'],
                    'label' => $label,
                    'module' => $r['module'],
                    'module_label' => AuditCatalog::MODULES[$r['module']] ?? $r['module'],
                    'method' => $r['method'],
                    'path' => $r['path'],
                    'entity_type' => $r['entity_type'],
                    'entity_id' => $r['entity_id'],
                    'subject' => $r['subject'],
                    'status' => (int) $r['status'],
                    'details' => json_decode((string) $r['details'], true) ?: new \stdClass(),
                ];
            }, $rows),
            'total' => $total,
            'perPage' => self::PER_PAGE,
            'page' => $page,
            'modules' => AuditCatalog::MODULES,
            'actors' => array_map('strval', $this->db->fetchFirstColumn('SELECT DISTINCT actor FROM audit_log ORDER BY 1 LIMIT 200')),
        ];
    }

    private static function labelFor(string $action): ?string
    {
        foreach (AuditCatalog::ROUTES as [$a, $label]) {
            if ($a === $action) {
                return $label;
            }
        }

        return null;
    }
}
