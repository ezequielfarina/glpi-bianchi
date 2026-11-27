<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;

/** Cadastro de itens do estoque (ADR-0014). */
final readonly class StockCatalog
{
    public function __construct(
        private Connection $db,
        private Refs $refs,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $name = $p->string('name', 150, required: true);
        $category = $p->string('category', 64, required: true);
        $tracked = $p->bool('tracked');
        $min = $p->int('min_quantity', 0, 100_000) ?? 0;
        $active = $p->bool('active', true);
        $notes = $p->string('notes', 2000);
        $typeCode = $p->string('type', 64);
        $typeId = null === $typeCode ? null : $this->refs->assetTypeId($typeCode);
        if (null !== $typeCode && null === $typeId) {
            $p->error('type', 'Tipo de ativo inválido.');
        }
        if ($tracked && null === $typeId) {
            $p->error('type', 'Item com patrimônio precisa do tipo de ativo (ex.: Monitor, Computador).');
        }
        if (null !== $name && false !== $this->db->fetchOne('SELECT 1 FROM stock_item WHERE lower(name) = lower(?) AND id <> ?', [$name, $id ?? 0])) {
            $p->error('name', 'Já existe um item com esse nome.');
        }
        $current = null;
        if (null !== $id) {
            $current = $this->db->fetchAssociative('SELECT tracked FROM stock_item WHERE id = ?', [$id]) ?: throw new NotFoundException('Item de estoque não encontrado.');
            $hasMovements = false !== $this->db->fetchOne('SELECT 1 FROM stock_movement WHERE stock_item_id = ? LIMIT 1', [$id]);
            if ($hasMovements && (bool) $current['tracked'] !== $tracked) {
                $p->error('tracked', 'Não dá para mudar o controle por patrimônio de um item que já tem movimentações.');
            }
        }
        $p->check();

        $row = ['name' => $name, 'category' => $category, 'tracked' => $tracked ? 'true' : 'false', 'min_quantity' => $min,
            'active' => $active ? 'true' : 'false', 'notes' => $notes, 'asset_type_id' => $typeId];
        if (null === $id) {
            return (int) $this->db->fetchOne(
                'INSERT INTO stock_item (name, category, tracked, min_quantity, active, notes, asset_type_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [...array_values($row), Refs::now(), Refs::now()],
            );
        }
        $this->db->update('stock_item', $row + ['updated_at' => Refs::now()], ['id' => $id]);

        return $id;
    }

    /** Exclui item sem histórico; com histórico, só desativa (o razão é imutável). */
    public function delete(int $id): string
    {
        if (false === $this->db->fetchOne('SELECT 1 FROM stock_item WHERE id = ?', [$id])) {
            throw new NotFoundException('Item de estoque não encontrado.');
        }
        $used = false !== $this->db->fetchOne('SELECT 1 FROM stock_movement WHERE stock_item_id = ? UNION ALL SELECT 1 FROM invoice_item WHERE stock_item_id = ? LIMIT 1', [$id, $id]);
        if ($used) {
            $balance = (int) $this->db->fetchOne('SELECT coalesce(sum(quantity), 0) FROM stock_movement WHERE stock_item_id = ?', [$id]);
            if (0 !== $balance) {
                throw new ValidationException(['id' => \sprintf('O item ainda tem %d unidade(s) em estoque. Zere o saldo antes de desativar.', $balance)]);
            }
            $this->db->executeStatement('UPDATE stock_item SET active = false, updated_at = ? WHERE id = ?', [Refs::now(), $id]);

            return 'deactivated';
        }
        $this->db->executeStatement('DELETE FROM stock_item WHERE id = ?', [$id]);

        return 'deleted';
    }
}
