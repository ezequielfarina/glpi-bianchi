<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Asset\Enum\AssetStatus;
use App\Asset\Service\InventoryNumberGenerator;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Movimentações do estoque (ADR-0014): entrada, saída para filial e ajuste.
 *
 * O saldo é sempre SUM(stock_movement.quantity) — nunca editado à mão. Cada operação trava as linhas
 * de `stock_item` envolvidas (FOR UPDATE, em ordem de id) antes de conferir saldo, então duas saídas
 * simultâneas não deixam o estoque negativo.
 *
 * Itens com patrimônio (`tracked`): a entrada cria um ativo `in_stock` por unidade (patrimônio automático,
 * serial opcional) e uma movimentação por unidade; a saída escolhe os ativos, que passam a `in_use` na filial.
 */
final readonly class StockLedger
{
    public const int MAX_UNITS = 500;
    public const array REMOVE_STATUSES = ['lost', 'disposed', 'retired'];

    public function __construct(
        private Connection $db,
        private InventoryNumberGenerator $numbers,
        private Refs $refs,
    ) {
    }

    /**
     * Entrada de mercadoria. Usada pela nota fiscal (com $invoiceItemId) e pela entrada manual.
     * Deve rodar dentro de uma transação aberta pelo chamador.
     *
     * @param list<string|null> $serials números de série por unidade (itens com patrimônio)
     *
     * @return list<int> ids dos ativos criados
     */
    public function receive(int $itemId, int $quantity, ?string $unitCost, ?int $invoiceItemId, Uuid $batch, string $actor,
        string $occurredAt, ?string $reason, array $serials = [], ?string $assetComment = null): array
    {
        $item = $this->lockItems([$itemId])[$itemId];
        $now = Refs::now();
        if (!$item['tracked']) {
            $this->insert($itemId, 'in', $quantity, $unitCost, $invoiceItemId, null, null, $batch, null, $reason, $actor, $occurredAt, $now);

            return [];
        }
        $assets = [];
        for ($i = 0; $i < $quantity; ++$i) {
            $serial = $serials[$i] ?? null;
            $assetId = $this->createAsset($item, $serial, $assetComment, $actor, $now);
            $this->insert($itemId, 'in', 1, $unitCost, $invoiceItemId, null, $assetId, $batch, null, $reason, $actor, $occurredAt, $now);
            $assets[] = $assetId;
        }

        return $assets;
    }

    /**
     * Entrada manual (saldo inicial, devolução, doação) sem nota fiscal.
     *
     * @param array<string, mixed> $data {occurred_at?, reason, lines: [{stock_item_id, quantity, unit_cost?, serials?}]}
     *
     * @return array{batch: string, assets: int}
     */
    public function manualIn(array $data, string $actor): array
    {
        $reason = self::text($data['reason'] ?? null, 500);
        $occurredAt = self::occurredAt($data['occurred_at'] ?? null);
        $lines = self::lines($data['lines'] ?? null, 'lines');
        $errors = [];
        if (null === $reason) {
            $errors['reason'] = 'Informe o motivo da entrada (ex.: saldo inicial, devolução).';
        }
        $parsed = [];
        foreach ($lines as $i => $l) {
            $qty = self::positiveInt($l['quantity'] ?? null);
            if (null === $qty || $qty > self::MAX_UNITS) {
                $errors["lines.$i.quantity"] = 'Quantidade inválida (1 a '.self::MAX_UNITS.').';
            }
            $cost = self::money($l['unit_cost'] ?? null);
            if (false === $cost) {
                $errors["lines.$i.unit_cost"] = 'Valor inválido.';
            }
            $parsed[] = ['item' => self::positiveInt($l['stock_item_id'] ?? null) ?? 0, 'qty' => $qty ?? 0, 'cost' => false === $cost ? null : $cost, 'serials' => self::serials($l['serials'] ?? null)];
        }
        if ([] !== $errors) {
            throw new ValidationException($errors);
        }
        $batch = Uuid::v7();

        return $this->db->transactional(function () use ($parsed, $batch, $actor, $occurredAt, $reason): array {
            $items = $this->lockItems(array_column($parsed, 'item'));
            $assets = 0;
            foreach ($parsed as $i => $l) {
                $this->assertSerials($items[$l['item']], $l['serials'], $l['qty'], "lines.$i.serials");
                $assets += \count($this->receive($l['item'], $l['qty'], $l['cost'], null, $batch, $actor, $occurredAt, $reason, $l['serials'], 'Entrada manual no estoque: '.$reason));
            }

            return ['batch' => $batch->toRfc4122(), 'assets' => $assets];
        });
    }

    /**
     * Saída para filial.
     *
     * @param array<string, mixed> $data {branch_id, recipient?, department?, occurred_at?, notes?, lines: [{stock_item_id, quantity} | {stock_item_id, assets: [uuid]}]}
     *
     * @return array{batch: string}
     */
    public function dispatch(array $data, string $actor): array
    {
        $errors = [];
        $branchId = self::positiveInt($data['branch_id'] ?? null);
        if (null === $branchId || false === $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ? AND active', [$branchId])) {
            $errors['branch_id'] = 'Escolha a filial de destino.';
        }
        $recipient = self::text($data['recipient'] ?? null, 150);
        $department = self::text($data['department'] ?? null, 64);
        $notes = self::text($data['notes'] ?? null, 2000);
        $occurredAt = self::occurredAt($data['occurred_at'] ?? null);
        $lines = self::lines($data['lines'] ?? null, 'lines');
        if ([] !== $errors) {
            throw new ValidationException($errors);
        }
        $batch = Uuid::v7();

        $this->db->transactional(function () use ($lines, $branchId, $recipient, $department, $notes, $occurredAt, $batch, $actor): void {
            $items = $this->lockItems(array_map(static fn (array $l): int => self::positiveInt($l['stock_item_id'] ?? null) ?? 0, $lines));
            $balances = $this->balances(array_keys($items));
            $errors = [];
            $now = Refs::now();
            $branchName = (string) $this->db->fetchOne('SELECT name FROM branch WHERE id = ?', [$branchId]);
            foreach ($lines as $i => $l) {
                $itemId = (int) $l['stock_item_id'];
                $item = $items[$itemId];
                if ($item['tracked']) {
                    $assets = $this->pickAssets($itemId, $l['assets'] ?? null, "lines.$i.assets", $errors);
                    foreach ($assets as $a) {
                        $this->insert($itemId, 'out', -1, $a['unit_cost'], null, $branchId, $a['id'], $batch, $recipient, $notes, $actor, $occurredAt, $now, $department);
                        $this->moveAsset($a['id'], AssetStatus::InUse->value, $branchId, $branchName, $actor, $now);
                    }
                    $balances[$itemId] -= \count($assets);
                    continue;
                }
                $qty = self::positiveInt($l['quantity'] ?? null);
                if (null === $qty) {
                    $errors["lines.$i.quantity"] = 'Quantidade inválida.';
                    continue;
                }
                if ($qty > $balances[$itemId]) {
                    $errors["lines.$i.quantity"] = \sprintf('Saldo insuficiente de %s (disponível: %d).', $item['name'], max(0, $balances[$itemId]));
                    continue;
                }
                $balances[$itemId] -= $qty;
                $this->insert($itemId, 'out', -$qty, $this->averageCost($itemId), null, $branchId, null, $batch, $recipient, $notes, $actor, $occurredAt, $now, $department);
            }
            if ([] !== $errors) {
                throw new ValidationException($errors);
            }
        });

        return ['batch' => $batch->toRfc4122()];
    }

    /**
     * Ajuste de saldo (contagem, perda, defeito) — sempre com motivo.
     * Item sem patrimônio: quantidade com sinal. Item com patrimônio: só baixa de unidades (ativos escolhidos),
     * que mudam para `lost`/`disposed`/`retired`; acréscimo de unidade com patrimônio é pela entrada manual.
     *
     * @param array<string, mixed> $data {stock_item_id, quantity?|assets?, asset_status?, reason, occurred_at?}
     */
    public function adjust(array $data, string $actor): void
    {
        $itemId = self::positiveInt($data['stock_item_id'] ?? null) ?? 0;
        $reason = self::text($data['reason'] ?? null, 500);
        $occurredAt = self::occurredAt($data['occurred_at'] ?? null);
        if (null === $reason) {
            throw new ValidationException(['reason' => 'Informe o motivo do ajuste.']);
        }

        $this->db->transactional(function () use ($itemId, $data, $reason, $occurredAt, $actor): void {
            $item = $this->lockItems([$itemId])[$itemId];
            $now = Refs::now();
            $batch = Uuid::v7();
            $errors = [];
            if ($item['tracked']) {
                $status = \is_string($data['asset_status'] ?? null) && \in_array($data['asset_status'], self::REMOVE_STATUSES, true) ? $data['asset_status'] : null;
                if (null === $status) {
                    $errors['asset_status'] = 'Escolha a nova situação das unidades (perdido, descartado, baixado).';
                }
                $assets = $this->pickAssets($itemId, $data['assets'] ?? null, 'assets', $errors);
                if ([] !== $errors) {
                    throw new ValidationException($errors);
                }
                foreach ($assets as $a) {
                    $this->insert($itemId, 'adjust', -1, $a['unit_cost'], null, null, $a['id'], $batch, null, $reason, $actor, $occurredAt, $now);
                    $this->moveAsset($a['id'], (string) $status, null, null, $actor, $now);
                }

                return;
            }
            $qty = $data['quantity'] ?? null;
            if (!\is_int($qty) && !(\is_string($qty) && preg_match('/^-?\d+$/', $qty))) {
                throw new ValidationException(['quantity' => 'Informe a quantidade (positiva para acrescentar, negativa para retirar).']);
            }
            $qty = (int) $qty;
            if (0 === $qty || abs($qty) > 100_000) {
                throw new ValidationException(['quantity' => 'Quantidade inválida.']);
            }
            $balance = $this->balances([$itemId])[$itemId];
            if ($balance + $qty < 0) {
                throw new ValidationException(['quantity' => \sprintf('O saldo ficaria negativo (atual: %d).', $balance)]);
            }
            $this->insert($itemId, 'adjust', $qty, $this->averageCost($itemId), null, null, null, $batch, null, $reason, $actor, $occurredAt, $now);
        });
    }

    /**
     * @param list<int> $itemIds
     *
     * @return array<int, int> saldo por item
     */
    public function balances(array $itemIds): array
    {
        $out = array_fill_keys($itemIds, 0);
        if ([] === $itemIds) {
            return $out;
        }
        $rows = $this->db->fetchAllKeyValue(
            'SELECT stock_item_id, coalesce(sum(quantity), 0) FROM stock_movement WHERE stock_item_id IN (:ids) GROUP BY stock_item_id',
            ['ids' => $itemIds], ['ids' => ArrayParameterType::INTEGER],
        );
        foreach ($rows as $id => $sum) {
            $out[(int) $id] = (int) $sum;
        }

        return $out;
    }

    /** Custo médio ponderado das entradas com valor (null se nunca houve entrada valorada). */
    public function averageCost(int $itemId): ?string
    {
        $v = $this->db->fetchOne(
            "SELECT round(sum(quantity * unit_cost) / nullif(sum(quantity), 0), 2) FROM stock_movement WHERE stock_item_id = ? AND kind = 'in' AND unit_cost IS NOT NULL",
            [$itemId],
        );

        return null === $v || false === $v ? null : (string) $v;
    }

    /**
     * Trava os itens (ordem de id: sem deadlock entre operações concorrentes) e devolve os dados.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{id: int, name: string, tracked: bool, active: bool, asset_type_id: int|null}>
     */
    public function lockItems(array $ids): array
    {
        if ([] === $ids || \in_array(0, $ids, true) || min($ids) < 0) {
            throw new ValidationException(['lines' => 'Escolha o item em todas as linhas.']);
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, tracked, active, asset_type_id FROM stock_item WHERE id IN (:ids) ORDER BY id FOR UPDATE',
            ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'tracked' => (bool) $r['tracked'],
                'active' => (bool) $r['active'], 'asset_type_id' => null === $r['asset_type_id'] ? null : (int) $r['asset_type_id']];
        }
        if (\count($out) !== \count($ids)) {
            throw new NotFoundException('Item de estoque não encontrado.');
        }

        return $out;
    }

    /**
     * @param array{id: int, name: string, tracked: bool, active: bool, asset_type_id: int|null} $item
     * @param list<string|null>                                                                  $serials
     */
    public function assertSerials(array $item, array $serials, int $quantity, string $field): void
    {
        if (!$item['tracked'] || [] === $serials) {
            return;
        }
        $filled = array_values(array_filter($serials, static fn (?string $s): bool => null !== $s));
        if (\count($serials) > $quantity) {
            throw new ValidationException([$field => \sprintf('%d número(s) de série para %d unidade(s).', \count($serials), $quantity)]);
        }
        $dupes = array_unique(array_diff_assoc(array_map('mb_strtolower', $filled), array_unique(array_map('mb_strtolower', $filled))));
        if ([] !== $dupes) {
            throw new ValidationException([$field => 'Número de série repetido: '.implode(', ', $dupes).'.']);
        }
        if ([] !== $filled) {
            $used = $this->db->fetchFirstColumn(
                'SELECT serial_number FROM asset WHERE deleted_at IS NULL AND lower(serial_number) IN (:s)',
                ['s' => array_map('mb_strtolower', $filled)], ['s' => ArrayParameterType::STRING],
            );
            if ([] !== $used) {
                throw new ValidationException([$field => 'Série já cadastrada em outro ativo: '.implode(', ', array_map('strval', $used)).'.']);
            }
        }
    }

    // ------------------------------------------------------------------ internos

    /**
     * Ativos em estoque deste item, escolhidos por UUID.
     *
     * @param array<string, string> $errors
     *
     * @return list<array{id: int, unit_cost: string|null}>
     */
    private function pickAssets(int $itemId, mixed $uuids, string $field, array &$errors): array
    {
        if (!\is_array($uuids) || [] === $uuids || \count($uuids) > self::MAX_UNITS) {
            $errors[$field] = 'Escolha as unidades (patrimônios).';

            return [];
        }
        $uuids = array_values(array_unique(array_filter($uuids, static fn (mixed $u): bool => \is_string($u) && Uuid::isValid($u))));
        // ativo em estoque = última movimentação dele neste item é positiva (entrou e não saiu)
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT a.id, a.uuid, last.unit_cost
            FROM asset a
            JOIN LATERAL (SELECT m.quantity, m.unit_cost FROM stock_movement m WHERE m.asset_id = a.id AND m.stock_item_id = :item ORDER BY m.id DESC LIMIT 1) last ON true
            WHERE a.uuid IN (:uuids) AND a.deleted_at IS NULL AND a.status = 'in_stock' AND last.quantity > 0
            ORDER BY a.id FOR UPDATE OF a
            SQL, ['item' => $itemId, 'uuids' => $uuids], ['uuids' => ArrayParameterType::STRING]);
        if (\count($rows) !== \count($uuids) || [] === $uuids) {
            $errors[$field] = 'Unidade inexistente ou que não está mais em estoque — atualize a tela.';

            return [];
        }

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'unit_cost' => null === $r['unit_cost'] ? null : (string) $r['unit_cost']], $rows);
    }

    /** @param array{id: int, name: string, tracked: bool, active: bool, asset_type_id: int|null} $item */
    private function createAsset(array $item, ?string $serial, ?string $comment, string $actor, string $now): int
    {
        if (null === $item['asset_type_id']) {
            throw new \LogicException('Item com patrimônio sem tipo de ativo.');
        }
        $inventoryNumber = $this->numbers->next();
        $id = (int) $this->db->fetchOne(<<<'SQL'
            INSERT INTO asset (uuid, organization_id, asset_type_id, name, status, serial_number, inventory_number, attributes, comment, dynamic, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'in_stock', ?, ?, '{}', ?, false, ?, ?) RETURNING id
            SQL, [Uuid::v7()->toRfc4122(), $this->refs->rootOrganizationId(), $item['asset_type_id'], $item['name'], $serial, $inventoryNumber, $comment, $now, $now]);
        $this->history($id, 'asset.created', null, $item['name'].' (entrada no estoque)', $actor, $now);

        return $id;
    }

    private function moveAsset(int $assetId, string $status, ?int $branchId, ?string $branchName, string $actor, string $now): void
    {
        $old = $this->db->fetchAssociative('SELECT a.status, b.name AS branch FROM asset a LEFT JOIN branch b ON b.id = a.branch_id WHERE a.id = ?', [$assetId]) ?: ['status' => null, 'branch' => null];
        if (null === $branchId) {
            $this->db->executeStatement('UPDATE asset SET status = ?, updated_at = ? WHERE id = ?', [$status, $now, $assetId]);
        } else {
            $this->db->executeStatement('UPDATE asset SET status = ?, branch_id = ?, updated_at = ? WHERE id = ?', [$status, $branchId, $now, $assetId]);
            if ($old['branch'] !== $branchName) {
                $this->history($assetId, 'branch', null === $old['branch'] ? null : (string) $old['branch'], $branchName, $actor, $now);
            }
        }
        $this->history($assetId, 'status', null === $old['status'] ? null : (string) $old['status'], $status, $actor, $now);
    }

    private function history(int $assetId, string $field, ?string $old, ?string $new, string $actor, string $now): void
    {
        $this->db->executeStatement(
            "INSERT INTO asset_history (asset_id, field, old_value, new_value, source, actor, created_at) VALUES (?, ?, ?, ?, 'manual', ?, ?)",
            [$assetId, $field, $old, $new, $actor, $now],
        );
    }

    private function insert(int $itemId, string $kind, int $qty, ?string $unitCost, ?int $invoiceItemId, ?int $branchId, ?int $assetId,
        Uuid $batch, ?string $recipient, ?string $reason, string $actor, string $occurredAt, string $now, ?string $department = null): void
    {
        $this->db->executeStatement(<<<'SQL'
            INSERT INTO stock_movement (stock_item_id, invoice_item_id, branch_id, asset_id, kind, quantity, unit_cost, batch, recipient, department, reason, actor, occurred_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL, [$itemId, $invoiceItemId, $branchId, $assetId, $kind, $qty, $unitCost, $batch->toRfc4122(), $recipient, $department, $reason, $actor, $occurredAt, $now]);
    }

    // ------------------------------------------------------------------ validação de entrada

    /** @return list<array<string, mixed>> */
    public static function lines(mixed $v, string $field): array
    {
        if (!\is_array($v) || [] === $v || !array_is_list($v) || \count($v) > 100) {
            throw new ValidationException([$field => 'Informe de 1 a 100 itens.']);
        }
        $out = [];
        foreach ($v as $i => $l) {
            if (!\is_array($l)) {
                throw new ValidationException(["$field.$i" => 'Linha inválida.']);
            }
            /** @var array<string, mixed> $l */
            $out[] = $l;
        }

        return $out;
    }

    public static function positiveInt(mixed $v): ?int
    {
        if (\is_int($v)) {
            return $v > 0 ? $v : null;
        }

        return \is_string($v) && preg_match('/^\d{1,9}$/', $v) && (int) $v > 0 ? (int) $v : null;
    }

    /** "1.234,56" | "1234.56" | 1234.56 → "1234.56"; null se vazio; false se inválido. */
    public static function money(mixed $v): string|false|null
    {
        if (null === $v || '' === $v) {
            return null;
        }
        if (\is_int($v) || \is_float($v)) {
            $s = (string) $v;
        } elseif (\is_string($v)) {
            $s = trim(str_replace(['R$', ' '], '', $v));
            if (str_contains($s, ',')) {
                $s = str_replace(['.', ','], ['', '.'], $s);
            }
        } else {
            return false;
        }
        if (!is_numeric($s) || (float) $s < 0 || (float) $s > 999_999_999_999) {
            return false;
        }

        return number_format((float) $s, 2, '.', '');
    }

    public static function text(mixed $v, int $max): ?string
    {
        if (!\is_string($v)) {
            return null;
        }
        $v = trim($v);

        return '' === $v ? null : mb_substr($v, 0, $max);
    }

    /** @return list<string|null> */
    public static function serials(mixed $v): array
    {
        if (\is_string($v)) {
            $v = preg_split('/\R/', $v) ?: [];
        }
        if (!\is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $s) {
            $s = \is_string($s) ? trim($s) : '';
            $out[] = '' === $s ? null : mb_substr($s, 0, 255);
        }
        while ([] !== $out && null === end($out)) {
            array_pop($out);
        }

        return $out;
    }

    /** Data do movimento (AAAA-MM-DD, até hoje) → timestamptz; vazio = agora. */
    public static function occurredAt(mixed $v): string
    {
        if (!\is_string($v) || '' === trim($v)) {
            return Refs::now();
        }
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($v), $tz);
        if (false === $d || $d->format('Y-m-d') !== trim($v)) {
            throw new ValidationException(['occurred_at' => 'Data inválida.']);
        }
        $today = new \DateTimeImmutable('today', $tz);
        if ($d > $today) {
            throw new ValidationException(['occurred_at' => 'A data não pode ser futura.']);
        }
        // hoje → hora atual; dia passado → meio-dia local (ordena bem e não muda de dia por fuso)
        return ($d->format('Y-m-d') === $today->format('Y-m-d') ? new \DateTimeImmutable('now', $tz) : $d->setTime(12, 0))->format('Y-m-d H:i:sP');
    }
}
