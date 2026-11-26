<?php

declare(strict_types=1);

namespace App\Inventory\Message;

/**
 * Mensagem da fila "inventory": processar o RawInventory com este id.
 * Carrega só o id — o payload fica no PostgreSQL (fila leve, sem duplicar MBs no Valkey).
 */
final readonly class ProcessInventory
{
    public function __construct(
        public string $rawInventoryId,
    ) {
    }
}
