<?php

declare(strict_types=1);

namespace App\Asset\Enum;

/**
 * Ciclo de vida do ativo. Persistido como string (legível em SQL / Grafana).
 */
enum AssetStatus: string
{
    case InStock = 'in_stock';
    case InUse = 'in_use';
    case InRepair = 'in_repair';
    case Reserved = 'reserved';
    case Retired = 'retired';
    case Disposed = 'disposed';
    case Lost = 'lost';
}
