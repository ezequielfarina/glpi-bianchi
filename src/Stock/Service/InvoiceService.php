<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Attachment\Service\AttachmentStorage;
use App\Stock\Entity\Invoice;
use App\Ui\ReadModel\ManageQuery;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Notas fiscais de compra simplificadas (ADR-0014): número, série, fornecedor, data, total e itens.
 * Registrar a nota dá entrada no estoque; a situação acompanha o envio ao setor de lançamento (que lança a nota).
 *
 * Itens da nota não são editáveis depois de salvos (já movimentaram estoque e criaram patrimônios):
 * para corrigir, exclui-se a nota — permitido enquanto nada do que ela trouxe saiu do estoque.
 */
final readonly class InvoiceService
{
    public const array STATUS_LABEL = ['registered' => 'Registrada', 'sent' => 'Enviada para lançamento'];

    public function __construct(
        private Connection $db,
        private StockLedger $ledger,
        private ManageQuery $manage,
        private AttachmentStorage $attachments,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $status = null, ?string $q = null, ?int $supplierId = null): array
    {
        $where = ['true'];
        $params = [];
        if (null !== $status && \in_array($status, Invoice::STATUSES, true)) {
            $where[] = 'n.status = :status';
            $params['status'] = $status;
        }
        if (null !== $supplierId && $supplierId > 0) {
            $where[] = 'n.supplier_id = :sup';
            $params['sup'] = $supplierId;
        }
        if (null !== $q && '' !== trim($q)) {
            $where[] = "(n.number ILIKE :q OR s.name ILIKE :q OR s.trade_name ILIKE :q OR EXISTS (
                SELECT 1 FROM invoice_item ii JOIN stock_item i ON i.id = ii.stock_item_id WHERE ii.invoice_id = n.id AND i.name ILIKE :q))";
            $params['q'] = '%'.trim($q).'%';
        }
        $w = implode(' AND ', $where);
        $rows = $this->db->fetchAllAssociative(<<<SQL
            SELECT n.id, n.number, n.series, n.issue_date, n.total, n.status, n.sent_at, n.created_at,
                   s.id AS supplier_id, s.name AS supplier,
                   coalesce(t.items_total, 0) AS items_total, coalesce(t.lines, 0) AS lines, coalesce(t.units, 0) AS units, t.summary
            FROM invoice n
            JOIN supplier s ON s.id = n.supplier_id
            LEFT JOIN (
                SELECT ii.invoice_id, sum(ii.quantity * ii.unit_price) AS items_total, count(*) AS lines, sum(ii.quantity) AS units,
                       string_agg(ii.quantity || '× ' || i.name, ', ' ORDER BY ii.position, ii.id) AS summary
                FROM invoice_item ii JOIN stock_item i ON i.id = ii.stock_item_id GROUP BY ii.invoice_id
            ) t ON t.invoice_id = n.id
            WHERE $w
            ORDER BY n.issue_date DESC, n.id DESC LIMIT 500
            SQL, $params);

        return array_map(static fn (array $r): array => self::header($r) + [
            'lines' => (int) $r['lines'], 'units' => (int) $r['units'], 'summary' => $r['summary'],
        ], $rows);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $r = $this->db->fetchAssociative('SELECT n.*, s.name AS supplier, s.tax_id AS supplier_tax_id,
                   (SELECT coalesce(sum(quantity * unit_price), 0) FROM invoice_item WHERE invoice_id = n.id) AS items_total
            FROM invoice n JOIN supplier s ON s.id = n.supplier_id WHERE n.id = ?', [$id]) ?: throw new NotFoundException('Nota fiscal não encontrada.');
        $items = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT ii.id, ii.stock_item_id, i.name AS item, i.category, i.tracked, ii.quantity, ii.unit_price,
                   round(ii.quantity * ii.unit_price, 2) AS subtotal
            FROM invoice_item ii JOIN stock_item i ON i.id = ii.stock_item_id
            WHERE ii.invoice_id = ? ORDER BY ii.position, ii.id
            SQL, [$id]);
        $assets = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT a.uuid, a.name, a.inventory_number, a.serial_number, a.status, b.name AS branch, m.invoice_item_id
            FROM stock_movement m
            JOIN invoice_item ii ON ii.id = m.invoice_item_id
            JOIN asset a ON a.id = m.asset_id
            LEFT JOIN branch b ON b.id = a.branch_id
            WHERE ii.invoice_id = ? AND m.kind = 'in' AND a.deleted_at IS NULL
            ORDER BY a.inventory_number
            SQL, [$id]);
        $blocker = $this->deleteBlocker($id);

        return self::header($r) + [
            'supplier_tax_id' => $r['supplier_tax_id'],
            'notes' => $r['notes'],
            'sent_by' => $r['sent_by'],
            'created_by' => $r['created_by'],
            'items' => array_map(static fn (array $i): array => [
                'id' => (int) $i['id'], 'stock_item_id' => (int) $i['stock_item_id'], 'item' => $i['item'], 'category' => $i['category'],
                'tracked' => (bool) $i['tracked'], 'quantity' => (int) $i['quantity'], 'unit_price' => $i['unit_price'], 'subtotal' => $i['subtotal'],
            ], $items),
            'assets' => array_map(static fn (array $a): array => $a + ['invoice_item_id' => (int) $a['invoice_item_id']], $assets),
            'attachments' => $this->manage->attachments('invoice', $id),
            'can_delete' => null === $blocker,
            'delete_blocker' => $blocker,
        ];
    }

    /**
     * @param array<string, mixed> $data {supplier_id, number, series?, issue_date, total, notes?, items: [{stock_item_id, quantity, unit_price, serials?}]}
     */
    public function create(array $data, string $actor): int
    {
        $h = $this->readHeader($data, null);
        $occurredAt = StockLedger::occurredAt($data['received_at'] ?? null);
        $lines = StockLedger::lines($data['items'] ?? null, 'items');
        $errors = [];
        $parsed = [];
        foreach ($lines as $i => $l) {
            $item = StockLedger::positiveInt($l['stock_item_id'] ?? null);
            $qty = StockLedger::positiveInt($l['quantity'] ?? null);
            $price = StockLedger::money($l['unit_price'] ?? null);
            if (null === $item) {
                $errors["items.$i.stock_item_id"] = 'Escolha o item.';
            }
            if (null === $qty || $qty > StockLedger::MAX_UNITS) {
                $errors["items.$i.quantity"] = 'Quantidade inválida (1 a '.StockLedger::MAX_UNITS.').';
            }
            if (null === $price || false === $price) {
                $errors["items.$i.unit_price"] = 'Informe o valor unitário.';
            }
            $parsed[] = ['item' => $item ?? 0, 'qty' => $qty ?? 0, 'price' => \is_string($price) ? $price : '0.00', 'serials' => StockLedger::serials($l['serials'] ?? null)];
        }
        if ([] !== $errors) {
            throw new ValidationException($errors);
        }

        return $this->db->transactional(function () use ($h, $parsed, $actor, $occurredAt): int {
            $items = $this->ledger->lockItems(array_column($parsed, 'item'));
            foreach ($parsed as $i => $l) {
                if (!$items[$l['item']]['active']) {
                    throw new ValidationException(["items.$i.stock_item_id" => 'Item desativado: '.$items[$l['item']]['name'].'.']);
                }
                $this->ledger->assertSerials($items[$l['item']], $l['serials'], $l['qty'], "items.$i.serials");
            }
            $now = Refs::now();
            $id = (int) $this->db->fetchOne(
                'INSERT INTO invoice (supplier_id, number, series, issue_date, total, notes, status, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$h['supplier_id'], $h['number'], $h['series'], $h['issue_date'], $h['total'], $h['notes'], 'registered', $actor, $now, $now],
            );
            $supplier = (string) $this->db->fetchOne('SELECT name FROM supplier WHERE id = ?', [$h['supplier_id']]);
            $label = \sprintf('NF %s%s — %s', $h['number'], '' === $h['series'] ? '' : '/'.$h['series'], $supplier);
            $batch = Uuid::v7();
            foreach ($parsed as $pos => $l) {
                $itemRowId = (int) $this->db->fetchOne(
                    'INSERT INTO invoice_item (invoice_id, stock_item_id, quantity, unit_price, position) VALUES (?, ?, ?, ?, ?) RETURNING id',
                    [$id, $l['item'], $l['qty'], $l['price'], $pos],
                );
                $this->ledger->receive($l['item'], $l['qty'], $l['price'], $itemRowId, $batch, $actor, $occurredAt, $label, $l['serials'], 'Entrada no estoque: '.$label);
            }

            return $id;
        });
    }

    /**
     * Cabeçalho (número, série, fornecedor, data, total, observações) — sempre editável.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        if (false === $this->db->fetchOne('SELECT 1 FROM invoice WHERE id = ?', [$id])) {
            throw new NotFoundException('Nota fiscal não encontrada.');
        }
        $h = $this->readHeader($data, $id);
        $this->db->update('invoice', $h + ['updated_at' => Refs::now()], ['id' => $id]);
    }

    public function setStatus(int $id, string $status, string $actor): void
    {
        if (!\in_array($status, Invoice::STATUSES, true)) {
            throw new ValidationException(['status' => 'Situação inválida.']);
        }
        $now = Refs::now();
        $n = 'sent' === $status
            ? $this->db->executeStatement('UPDATE invoice SET status = ?, sent_at = coalesce(sent_at, ?), sent_by = coalesce(sent_by, ?), updated_at = ? WHERE id = ?',
                [$status, $now, $actor, $now, $id])
            : $this->db->executeStatement('UPDATE invoice SET status = ?, sent_at = NULL, sent_by = NULL, updated_at = ? WHERE id = ?', [$status, $now, $id]);
        if (0 === $n) {
            throw new NotFoundException('Nota fiscal não encontrada.');
        }
    }

    public function delete(int $id): void
    {
        $this->db->transactional(function () use ($id): void {
            if (false === $this->db->fetchOne('SELECT 1 FROM invoice WHERE id = ? FOR UPDATE', [$id])) {
                throw new NotFoundException('Nota fiscal não encontrada.');
            }
            $itemIds = array_map('intval', $this->db->fetchFirstColumn('SELECT DISTINCT stock_item_id FROM invoice_item WHERE invoice_id = ?', [$id]));
            if ([] !== $itemIds) {
                $this->ledger->lockItems($itemIds);
            }
            $blocker = $this->deleteBlocker($id);
            if (null !== $blocker) {
                throw new ValidationException(['id' => $blocker]);
            }
            $assetIds = array_map('intval', $this->db->fetchFirstColumn(
                'SELECT m.asset_id FROM stock_movement m JOIN invoice_item ii ON ii.id = m.invoice_item_id WHERE ii.invoice_id = ? AND m.asset_id IS NOT NULL', [$id]));
            $this->db->executeStatement('DELETE FROM invoice WHERE id = ?', [$id]);  // itens e movimentações em cascata
            foreach ($assetIds as $assetId) {
                $this->attachments->deleteAllOf('asset', $assetId);
                $this->db->executeStatement('DELETE FROM asset WHERE id = ?', [$assetId]);
            }
            $this->attachments->deleteAllOf('invoice', $id);
        });
    }

    /** Motivo que impede excluir a nota (null = pode excluir). */
    private function deleteBlocker(int $id): ?string
    {
        // patrimônio que já saiu (ou mudou de situação) depois da entrada por esta nota
        $moved = $this->db->fetchFirstColumn(<<<'SQL'
            SELECT a.inventory_number FROM stock_movement m
            JOIN invoice_item ii ON ii.id = m.invoice_item_id
            JOIN asset a ON a.id = m.asset_id
            WHERE ii.invoice_id = ? AND (a.status <> 'in_stock' OR a.deleted_at IS NOT NULL
                  OR EXISTS (SELECT 1 FROM stock_movement o WHERE o.asset_id = a.id AND o.id > m.id))
            ORDER BY 1 LIMIT 5
            SQL, [$id]);
        if ([] !== $moved) {
            return 'Patrimônio(s) desta nota já saíram do estoque: '.implode(', ', array_map('strval', $moved)).'.';
        }
        // sem patrimônio: o saldo atual precisa cobrir o que a nota trouxe
        $short = $this->db->fetchFirstColumn(<<<'SQL'
            SELECT i.name FROM (SELECT stock_item_id, sum(quantity) AS q FROM invoice_item WHERE invoice_id = ? GROUP BY stock_item_id) n
            JOIN stock_item i ON i.id = n.stock_item_id AND NOT i.tracked
            WHERE (SELECT coalesce(sum(quantity), 0) FROM stock_movement WHERE stock_item_id = n.stock_item_id) < n.q
            SQL, [$id]);
        if ([] !== $short) {
            return 'Parte dos itens desta nota já saiu do estoque ('.implode(', ', array_map('strval', $short)).'). Ajuste o saldo antes de excluir.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{supplier_id: int, number: string, series: string, issue_date: string, total: string, notes: string|null}
     */
    private function readHeader(array $data, ?int $id): array
    {
        $p = new Payload($data);
        $supplierId = $p->int('supplier_id', 1, required: true);
        $number = $p->string('number', 20, required: true);
        $series = $p->string('series', 5) ?? '';
        $issueDate = $p->date('issue_date', required: true);
        $total = $p->money('total');
        $notes = $p->string('notes', 2000);
        if (null !== $number) {
            $number = ltrim(preg_replace('/\s+/', '', $number) ?? $number, '0') ?: '0';
            if (!preg_match('/^[0-9A-Za-z.\-\/]+$/', $number)) {
                $p->error('number', 'Use só números (e letras, se a nota tiver).');
            }
        }
        if (null === ($data['total'] ?? null) || '' === $data['total']) {
            $p->error('total', 'Informe o valor total da nota.');
        }
        if (null !== $supplierId && false === $this->db->fetchOne('SELECT 1 FROM supplier WHERE id = ?', [$supplierId])) {
            $p->error('supplier_id', 'Fornecedor inexistente.');
        }
        if (null !== $issueDate && $issueDate > (new \DateTimeImmutable('today', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d')) {
            $p->error('issue_date', 'A data de emissão não pode ser futura.');
        }
        if (null !== $supplierId && null !== $number && false !== $this->db->fetchOne(
            'SELECT 1 FROM invoice WHERE supplier_id = ? AND number = ? AND series = ? AND id <> ?', [$supplierId, $number, $series, $id ?? 0])) {
            $p->error('number', 'Esta nota já foi registrada para este fornecedor.');
        }
        $p->check();

        /** @var array{supplier_id: int, number: string, series: string, issue_date: string, total: string, notes: string|null} */
        return ['supplier_id' => (int) $supplierId, 'number' => (string) $number, 'series' => $series, 'issue_date' => (string) $issueDate,
            'total' => (string) $total, 'notes' => $notes];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function header(array $r): array
    {
        $diff = round((float) $r['total'] - (float) $r['items_total'], 2);

        return [
            'id' => (int) $r['id'],
            'number' => $r['number'],
            'series' => $r['series'],
            'supplier_id' => (int) $r['supplier_id'],
            'supplier' => $r['supplier'],
            'issue_date' => $r['issue_date'],
            'total' => $r['total'],
            'items_total' => number_format((float) $r['items_total'], 2, '.', ''),
            'difference' => number_format($diff, 2, '.', ''),
            'status' => $r['status'],
            'status_label' => self::STATUS_LABEL[$r['status']] ?? $r['status'],
            'sent_at' => $r['sent_at'],
            'created_at' => $r['created_at'],
        ];
    }
}
