<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Ui\Write\NotFoundException;
use Doctrine\DBAL\Connection;

/** Leituras do estoque (ADR-0014): saldo, "a comprar", histórico e consumo por filial. */
final readonly class StockQuery
{
    /** saldo, custo médio (entradas valoradas) e última entrada/saída por item */
    private const string AGG = <<<'SQL'
        SELECT stock_item_id,
               sum(quantity) AS balance,
               round(sum(quantity * unit_cost) FILTER (WHERE kind = 'in' AND unit_cost IS NOT NULL)
                     / nullif(sum(quantity) FILTER (WHERE kind = 'in' AND unit_cost IS NOT NULL), 0), 2) AS avg_cost,
               max(occurred_at) FILTER (WHERE kind = 'in') AS last_in,
               max(occurred_at) FILTER (WHERE kind = 'out') AS last_out
        FROM stock_movement GROUP BY stock_item_id
        SQL;

    public const array KIND_LABEL = ['in' => 'Entrada', 'out' => 'Saída', 'adjust' => 'Ajuste'];

    public function __construct(private Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(?string $q = null, ?string $category = null, bool $lowOnly = false, bool $includeInactive = false): array
    {
        $where = [];
        $params = [];
        if (!$includeInactive) {
            $where[] = 'i.active';
        }
        if (null !== $q && '' !== trim($q)) {
            $where[] = '(i.name ILIKE :q OR i.category ILIKE :q)';
            $params['q'] = '%'.trim($q).'%';
        }
        if (null !== $category && '' !== $category) {
            $where[] = 'i.category = :cat';
            $params['cat'] = $category;
        }
        if ($lowOnly) {
            $where[] = 'i.active AND i.min_quantity > 0 AND coalesce(s.balance, 0) < i.min_quantity';
        }
        $sql = 'SELECT i.id, i.name, i.category, i.tracked, i.min_quantity, i.active, i.notes, t.code AS type_code, t.label AS type_label,
                       coalesce(s.balance, 0) AS balance, s.avg_cost, s.last_in, s.last_out,
                       (SELECT count(*) FROM stock_movement m WHERE m.stock_item_id = i.id) AS movements
                FROM stock_item i
                LEFT JOIN asset_type t ON t.id = i.asset_type_id
                LEFT JOIN ('.self::AGG.') s ON s.stock_item_id = i.id
                '.([] === $where ? '' : 'WHERE '.implode(' AND ', $where)).'
                ORDER BY (i.active AND i.min_quantity > 0 AND coalesce(s.balance, 0) < i.min_quantity) DESC, i.category, i.name';

        return array_map(self::itemRow(...), $this->db->fetchAllAssociative($sql, $params));
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $items = $this->items();
        $low = array_values(array_filter($items, static fn (array $i): bool => (bool) $i['low']));

        return [
            'items' => \count($items),
            'units' => array_sum(array_column($items, 'balance')),
            'value' => number_format(array_sum(array_map(static fn (array $i): float => (float) ($i['value'] ?? 0), $items)), 2, '.', ''),
            'low' => \count($low),
            'to_buy' => array_sum(array_column($low, 'to_buy')),
            'invoices_pending' => (int) $this->db->fetchOne("SELECT count(*) FROM invoice WHERE status = 'registered'"),
            'categories' => $this->categories(),
        ];
    }

    public function lowCount(): int
    {
        return (int) $this->db->fetchOne('SELECT count(*) FROM stock_item i LEFT JOIN ('.self::AGG.') s ON s.stock_item_id = i.id
            WHERE i.active AND i.min_quantity > 0 AND coalesce(s.balance, 0) < i.min_quantity');
    }

    /** @return list<string> */
    public function categories(): array
    {
        $used = array_map('strval', $this->db->fetchFirstColumn('SELECT DISTINCT category FROM stock_item ORDER BY 1'));

        return array_values(array_unique([...$used, ...['Mouse', 'Teclado', 'Monitor', 'CPU', 'Notebook', 'Cabo', 'Periférico', 'Rede', 'Suprimento']]));
    }

    /** @return array<string, mixed> */
    public function item(int $id): array
    {
        $rows = array_filter($this->items(includeInactive: true), static fn (array $i): bool => $i['id'] === $id);
        $item = array_shift($rows) ?? throw new NotFoundException('Item de estoque não encontrado.');
        $item['units'] = $item['tracked'] ? $this->unitsInStock($id) : [];
        $item['movements_recent'] = $this->movements(['item' => $id, 'limit' => 100])['items'];
        $item['consumption'] = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT b.id AS branch_id, b.name AS branch, -sum(m.quantity) AS quantity, max(m.occurred_at) AS last_at
            FROM stock_movement m JOIN branch b ON b.id = m.branch_id
            WHERE m.stock_item_id = ? AND m.kind = 'out' AND m.occurred_at >= now() - interval '365 days'
            GROUP BY b.id, b.name ORDER BY 3 DESC
            SQL, [$id]);

        return $item;
    }

    /**
     * Unidades (ativos) deste item ainda em estoque — para escolher na saída/ajuste.
     *
     * @return list<array<string, mixed>>
     */
    public function unitsInStock(int $itemId): array
    {
        return $this->db->fetchAllAssociative(<<<'SQL'
            SELECT a.uuid, a.inventory_number, a.serial_number, last.occurred_at AS since, last.unit_cost,
                   inv.number AS invoice_number, inv.id AS invoice_id
            FROM asset a
            JOIN LATERAL (SELECT m.quantity, m.unit_cost, m.occurred_at, m.invoice_item_id FROM stock_movement m
                          WHERE m.asset_id = a.id AND m.stock_item_id = ? ORDER BY m.id DESC LIMIT 1) last ON true
            LEFT JOIN invoice_item ii ON ii.id = last.invoice_item_id
            LEFT JOIN invoice inv ON inv.id = ii.invoice_id
            WHERE a.deleted_at IS NULL AND a.status = 'in_stock' AND last.quantity > 0
            ORDER BY a.inventory_number
            SQL, [$itemId]);
    }

    /**
     * @param array{item?: int|null, branch?: int|null, kind?: string|null, from?: string|null, to?: string|null, limit?: int, invoice?: int|null} $f
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function movements(array $f): array
    {
        $where = ['true'];
        $params = [];
        foreach (['item' => 'm.stock_item_id', 'branch' => 'm.branch_id'] as $k => $col) {
            if (isset($f[$k]) && $f[$k] > 0) {
                $where[] = "$col = :$k";
                $params[$k] = $f[$k];
            }
        }
        if (isset($f['invoice']) && $f['invoice'] > 0) {
            $where[] = 'ii.invoice_id = :invoice';
            $params['invoice'] = $f['invoice'];
        }
        if (isset($f['kind']) && \array_key_exists($f['kind'], self::KIND_LABEL)) {
            $where[] = 'm.kind = :kind';
            $params['kind'] = $f['kind'];
        }
        if (isset($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
            $where[] = "m.occurred_at >= CAST(:from AS date)::timestamp AT TIME ZONE 'America/Sao_Paulo'";
            $params['from'] = $f['from'];
        }
        if (isset($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
            $where[] = "m.occurred_at < (CAST(:to AS date) + 1)::timestamp AT TIME ZONE 'America/Sao_Paulo'";
            $params['to'] = $f['to'];
        }
        $limit = max(1, min(1000, $f['limit'] ?? 200));
        $w = implode(' AND ', $where);
        // agrupa a operação (batch + item): uma saída de 5 monitores com patrimônio vira uma linha com a lista de patrimônios
        $rows = $this->db->fetchAllAssociative(<<<SQL
            SELECT min(m.id) AS id, m.batch, m.kind, i.id AS item_id, i.name AS item, i.category, sum(m.quantity) AS quantity,
                   max(m.unit_cost) AS unit_cost, max(m.occurred_at) AS occurred_at, m.branch_id, max(b.name) AS branch,
                   max(m.recipient) AS recipient, max(m.reason) AS reason, max(m.actor) AS actor,
                   max(inv.id) AS invoice_id, max(inv.number) AS invoice_number, max(sup.name) AS supplier,
                   array_remove(array_agg(a.inventory_number ORDER BY a.inventory_number), NULL) AS inventory_numbers,
                   array_remove(array_agg(a.uuid::text ORDER BY a.inventory_number), NULL) AS asset_uuids
            FROM stock_movement m
            JOIN stock_item i ON i.id = m.stock_item_id
            LEFT JOIN branch b ON b.id = m.branch_id
            LEFT JOIN asset a ON a.id = m.asset_id
            LEFT JOIN invoice_item ii ON ii.id = m.invoice_item_id
            LEFT JOIN invoice inv ON inv.id = ii.invoice_id
            LEFT JOIN supplier sup ON sup.id = inv.supplier_id
            WHERE $w
            GROUP BY m.batch, m.kind, i.id, i.name, i.category, m.branch_id, coalesce(m.invoice_item_id, 0), CASE WHEN m.batch IS NULL THEN m.id END
            ORDER BY max(m.occurred_at) DESC, min(m.id) DESC
            LIMIT $limit
            SQL, $params);
        $total = (int) $this->db->fetchOne("SELECT count(*) FROM stock_movement m LEFT JOIN invoice_item ii ON ii.id = m.invoice_item_id WHERE $w", $params);

        return ['items' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'batch' => $r['batch'],
                'kind' => $r['kind'],
                'kind_label' => self::KIND_LABEL[$r['kind']] ?? $r['kind'],
                'item_id' => (int) $r['item_id'],
                'item' => $r['item'],
                'category' => $r['category'],
                'quantity' => (int) $r['quantity'],
                'unit_cost' => $r['unit_cost'],
                'occurred_at' => $r['occurred_at'],
                'branch_id' => null === $r['branch_id'] ? null : (int) $r['branch_id'],
                'branch' => $r['branch'],
                'recipient' => $r['recipient'],
                'reason' => $r['reason'],
                'actor' => $r['actor'],
                'invoice_id' => null === $r['invoice_id'] ? null : (int) $r['invoice_id'],
                'invoice_number' => $r['invoice_number'],
                'supplier' => $r['supplier'],
                'inventory_numbers' => self::pgArray($r['inventory_numbers']),
                'asset_uuids' => self::pgArray($r['asset_uuids']),
            ];
        }, $rows), 'total' => $total];
    }

    /**
     * Consumo (saídas) por filial e item no período.
     *
     * @return array{from: string, to: string, branches: list<array<string, mixed>>}
     */
    public function consumption(?string $from, ?string $to): array
    {
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $toD = null !== $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : (new \DateTimeImmutable('today', $tz))->format('Y-m-d');
        $fromD = null !== $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : (new \DateTimeImmutable($toD, $tz))->modify('-89 days')->format('Y-m-d');
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT b.id AS branch_id, b.name AS branch, i.id AS item_id, i.name AS item, i.category,
                   -sum(m.quantity) AS quantity, round(-sum(m.quantity * coalesce(m.unit_cost, 0)), 2) AS value
            FROM stock_movement m
            JOIN branch b ON b.id = m.branch_id
            JOIN stock_item i ON i.id = m.stock_item_id
            WHERE m.kind = 'out'
              AND m.occurred_at >= CAST(:from AS date)::timestamp AT TIME ZONE 'America/Sao_Paulo'
              AND m.occurred_at < (CAST(:to AS date) + 1)::timestamp AT TIME ZONE 'America/Sao_Paulo'
            GROUP BY b.id, b.name, i.id, i.name, i.category
            ORDER BY b.name, i.category, i.name
            SQL, ['from' => $fromD, 'to' => $toD]);
        $branches = [];
        foreach ($rows as $r) {
            $bid = (int) $r['branch_id'];
            $branches[$bid] ??= ['branch_id' => $bid, 'branch' => $r['branch'], 'quantity' => 0, 'value' => 0.0, 'items' => []];
            $branches[$bid]['quantity'] += (int) $r['quantity'];
            $branches[$bid]['value'] += (float) $r['value'];
            $branches[$bid]['items'][] = ['item_id' => (int) $r['item_id'], 'item' => $r['item'], 'category' => $r['category'], 'quantity' => (int) $r['quantity'], 'value' => $r['value']];
        }
        $list = array_values($branches);
        usort($list, static fn (array $a, array $b): int => $b['quantity'] <=> $a['quantity']);
        foreach ($list as &$b) {
            $b['value'] = number_format($b['value'], 2, '.', '');
        }
        unset($b);

        return ['from' => $fromD, 'to' => $toD, 'branches' => $list];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function itemRow(array $r): array
    {
        $balance = (int) $r['balance'];
        $min = (int) $r['min_quantity'];
        $low = (bool) $r['active'] && $min > 0 && $balance < $min;

        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'category' => $r['category'],
            'tracked' => (bool) $r['tracked'],
            'type_code' => $r['type_code'],
            'type_label' => $r['type_label'],
            'min_quantity' => $min,
            'active' => (bool) $r['active'],
            'notes' => $r['notes'],
            'balance' => $balance,
            'low' => $low,
            'to_buy' => $low ? $min - $balance : 0,
            'avg_cost' => $r['avg_cost'],
            'value' => null === $r['avg_cost'] ? null : number_format($balance * (float) $r['avg_cost'], 2, '.', ''),
            'last_in' => $r['last_in'],
            'last_out' => $r['last_out'],
            'movements' => (int) $r['movements'],
        ];
    }

    /** @return list<string> */
    private static function pgArray(mixed $v): array
    {
        if (\is_array($v)) {
            return array_values(array_map('strval', $v));
        }
        $s = trim((string) $v, '{}');
        if ('' === $s) {
            return [];
        }

        return array_map(static fn (string $x): string => trim($x, '"'), explode(',', $s));
    }
}
