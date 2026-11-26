<?php

declare(strict_types=1);

namespace App\Asset\Enum;

/** Origem de uma alteração registrada em `asset_history`. */
enum ChangeSource: string
{
    case Manual = 'manual';
    case Inventory = 'inventory';
    case Api = 'api';
    case System = 'system';
}
