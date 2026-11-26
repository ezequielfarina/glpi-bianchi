<?php

declare(strict_types=1);

namespace App\Inventory\Enum;

/**
 * Estados do payload recebido do agente:
 *
 *   received ──► processing ──► processed
 *                    │
 *                    └────────► failed   (retry pelo Messenger; após 3x vai para a fila "failed")
 *   received ──► rejected (schema inválido / itemtype não suportado — sem retry)
 */
enum RawInventoryStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Rejected = 'rejected';
}
