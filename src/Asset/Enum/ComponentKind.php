<?php

declare(strict_types=1);

namespace App\Asset\Enum;

/**
 * Tipos de componente. Equivale às tabelas `glpi_device*` + `glpi_items_device*`
 * do GLPI, unificadas em uma única tabela `component` (ver ADR-0003).
 */
enum ComponentKind: string
{
    case Processor = 'processor';
    case Memory = 'memory';
    case Storage = 'storage';
    case GraphicCard = 'graphic_card';
    case NetworkCard = 'network_card';
    case SoundCard = 'sound_card';
    case Controller = 'controller';
    case Firmware = 'firmware';
    case Battery = 'battery';
    case PowerSupply = 'power_supply';
    case Drive = 'drive';
    case Camera = 'camera';
    case Sensor = 'sensor';
    case Simcard = 'simcard';
    case Motherboard = 'motherboard';
}
