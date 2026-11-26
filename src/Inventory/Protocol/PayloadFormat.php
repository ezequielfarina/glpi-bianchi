<?php

declare(strict_types=1);

namespace App\Inventory\Protocol;

enum PayloadFormat: string
{
    case Json = 'json';
    case Xml = 'xml';
}
