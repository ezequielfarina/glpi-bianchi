<?php

declare(strict_types=1);

namespace App\Dcim\Service;

/**
 * Listas de opções do DCIM (ADR-0016). Subconjunto das *choices* do NetBox
 * (netbox/dcim/choices.py: InterfaceTypeChoices, PortTypeChoices, ConsolePortTypeChoices,
 * PowerPortTypeChoices, PowerOutletTypeChoices, CableTypeChoices, ColorChoices), com rótulos em
 * português e os plugues brasileiros NBR 14136.
 */
final class DcimChoices
{
    public const array PORT_KINDS = [
        'interface' => 'Interface de rede',
        'front' => 'Porta frontal (patch)',
        'rear' => 'Porta traseira (patch)',
        'console' => 'Porta de console',
        'console_server' => 'Servidor de console',
        'power_port' => 'Entrada de energia',
        'power_outlet' => 'Tomada de energia',
    ];

    /** Tipos físicos por tipo de porta. @var array<string, array<string, string>> */
    public const array PORT_TYPES = [
        'interface' => [
            'virtual' => 'Virtual', 'lag' => 'LAG (agregação)', 'bridge' => 'Bridge',
            '100base-tx' => '100BASE-TX (10/100)', '1000base-t' => '1000BASE-T (1GE)', '2.5gbase-t' => '2.5GBASE-T', '5gbase-t' => '5GBASE-T',
            '10gbase-t' => '10GBASE-T', '1000base-x-sfp' => 'SFP (1GE)', '10gbase-x-sfpp' => 'SFP+ (10GE)', '25gbase-x-sfp28' => 'SFP28 (25GE)',
            '40gbase-x-qsfpp' => 'QSFP+ (40GE)', '100gbase-x-qsfp28' => 'QSFP28 (100GE)', 'gpon' => 'GPON (ONT)', 'xdsl' => 'xDSL',
            'ieee802.11ac' => 'Wi-Fi 5 (802.11ac)', 'ieee802.11ax' => 'Wi-Fi 6 (802.11ax)', 'ieee802.11be' => 'Wi-Fi 7 (802.11be)', 'lte' => '4G/LTE', '5g' => '5G', 'other' => 'Outro',
        ],
        'front' => [
            '8p8c' => 'RJ45 (8P8C)', '110-punch' => '110 Punch', 'bnc' => 'BNC', 'lc' => 'LC', 'lc-apc' => 'LC/APC', 'sc' => 'SC', 'sc-apc' => 'SC/APC',
            'st' => 'ST', 'fc' => 'FC', 'mpo' => 'MPO', 'splice' => 'Fusão (splice)', 'other' => 'Outro',
        ],
        'rear' => [
            '8p8c' => 'RJ45 (8P8C)', '110-punch' => '110 Punch', 'bnc' => 'BNC', 'lc' => 'LC', 'lc-apc' => 'LC/APC', 'sc' => 'SC', 'sc-apc' => 'SC/APC',
            'st' => 'ST', 'fc' => 'FC', 'mpo' => 'MPO', 'splice' => 'Fusão (splice)', 'other' => 'Outro',
        ],
        'console' => ['rj-45' => 'RJ-45', 'de-9' => 'DB-9', 'usb-a' => 'USB-A', 'usb-c' => 'USB-C', 'usb-micro-b' => 'USB Micro-B', 'other' => 'Outro'],
        'console_server' => ['rj-45' => 'RJ-45', 'de-9' => 'DB-9', 'usb-a' => 'USB-A', 'usb-c' => 'USB-C', 'usb-micro-b' => 'USB Micro-B', 'other' => 'Outro'],
        'power_port' => [
            'nbr-14136-10a' => 'NBR 14136 10A', 'nbr-14136-20a' => 'NBR 14136 20A', 'iec-60320-c14' => 'IEC C14', 'iec-60320-c20' => 'IEC C20',
            'iec-60309-2p-e-6h' => 'IEC 60309 (industrial)', 'dc-terminal' => 'Borne CC', 'usb-c' => 'USB-C (PD)', 'hardwired' => 'Ligação direta', 'other' => 'Outro',
        ],
        'power_outlet' => [
            'nbr-14136-10a' => 'NBR 14136 10A', 'nbr-14136-20a' => 'NBR 14136 20A', 'iec-60320-c13' => 'IEC C13', 'iec-60320-c19' => 'IEC C19',
            'iec-60309-2p-e-6h' => 'IEC 60309 (industrial)', 'dc-terminal' => 'Borne CC', 'usb-a' => 'USB-A', 'hardwired' => 'Disjuntor / ligação direta', 'other' => 'Outro',
        ],
    ];

    /** Interfaces que não recebem cabo (netbox/dcim/constants.py VIRTUAL_IFACE_TYPES + WIRELESS_IFACE_TYPES). */
    public const array NON_CONNECTABLE = ['virtual', 'lag', 'bridge', 'ieee802.11ac', 'ieee802.11ax', 'ieee802.11be', 'lte', '5g'];

    /** Portas que são ponta de caminho (PathEndpoint no NetBox); front/rear são de passagem. */
    public const array ENDPOINT_KINDS = ['interface', 'console', 'console_server', 'power_port', 'power_outlet'];

    /**
     * Combinações permitidas entre as pontas de um cabo (netbox/dcim/constants.py
     * COMPATIBLE_TERMINATION_TYPES). `circuit` = terminação de circuito.
     *
     * @var array<string, list<string>>
     */
    public const array COMPATIBLE = [
        'interface' => ['interface', 'front', 'rear', 'circuit'],
        'front' => ['interface', 'front', 'rear', 'console', 'console_server', 'circuit'],
        'rear' => ['interface', 'front', 'rear', 'console', 'console_server', 'circuit'],
        'console' => ['console_server', 'front', 'rear'],
        'console_server' => ['console', 'front', 'rear'],
        'power_port' => ['power_outlet'],
        'power_outlet' => ['power_port'],
        'circuit' => ['interface', 'front', 'rear', 'circuit'],
    ];

    public const array CABLE_TYPES = [
        'cat5e' => 'CAT5e', 'cat6' => 'CAT6', 'cat6a' => 'CAT6a', 'cat7' => 'CAT7', 'cat8' => 'CAT8',
        'mmf-om3' => 'Fibra multimodo OM3', 'mmf-om4' => 'Fibra multimodo OM4', 'mmf-om5' => 'Fibra multimodo OM5', 'smf-os2' => 'Fibra monomodo OS2',
        'dac-passive' => 'DAC passivo', 'dac-active' => 'DAC ativo', 'aoc' => 'Cabo óptico ativo (AOC)', 'coaxial' => 'Coaxial',
        'power' => 'Energia', 'usb' => 'USB', 'other' => 'Outro',
    ];

    public const array CABLE_STATUS = ['connected' => 'Conectado', 'planned' => 'Planejado', 'decommissioning' => 'Em desativação'];

    public const array LENGTH_UNITS = ['m' => 'm', 'cm' => 'cm', 'km' => 'km', 'ft' => 'pés'];

    /** Cores padrão de cabo (hex sem #). */
    public const array COLORS = [
        '2196f3' => 'Azul', 'f44336' => 'Vermelho', '4caf50' => 'Verde', 'ffeb3b' => 'Amarelo', 'ff9800' => 'Laranja', '9e9e9e' => 'Cinza',
        '111111' => 'Preto', 'ffffff' => 'Branco', '9c27b0' => 'Roxo', 'e91e63' => 'Rosa', '795548' => 'Marrom', '00bcd4' => 'Ciano', 'aa1409' => 'Vinho',
    ];

    public const array DEVICE_STATUS = [
        'active' => 'Ativo', 'planned' => 'Planejado', 'staged' => 'Em preparação', 'offline' => 'Desligado', 'failed' => 'Com falha',
        'inventory' => 'Em estoque', 'decommissioning' => 'Em desativação',
    ];

    public const array RACK_STATUS = ['active' => 'Ativo', 'planned' => 'Planejado', 'reserved' => 'Reservado', 'available' => 'Disponível', 'deprecated' => 'Obsoleto'];

    public const array LOCATION_KINDS = [
        'building' => 'Prédio', 'floor' => 'Andar', 'room' => 'Sala', 'datacenter' => 'CPD / sala técnica', 'workstation' => 'Posto de trabalho (mesa)', 'other' => 'Outro',
    ];

    public const array RACK_WIDTHS = [10, 19, 21, 23];

    /** Desenho do equipamento no mapa da rede (ADR-0018). */
    public const array ROLE_ICONS = [
        'switch' => 'Switch', 'router' => 'Roteador', 'firewall' => 'Firewall', 'server' => 'Servidor', 'hypervisor' => 'Host de virtualização',
        'storage' => 'Storage / NAS', 'patch_panel' => 'Patch panel', 'dio' => 'DIO (fibra)', 'jack' => 'Ponto de rede (tomada)', 'pdu' => 'PDU / régua',
        'power_panel' => 'Quadro de energia', 'ups' => 'Nobreak', 'ont' => 'Modem / ONT', 'ap' => 'Access point', 'workstation' => 'Computador',
        'printer' => 'Impressora', 'phone' => 'Telefone IP', 'camera' => 'Câmera / NVR', 'organizer' => 'Organizador', 'vm' => 'Máquina virtual', 'generic' => 'Genérico',
    ];

    /** @return array<string, mixed> tudo que a interface precisa para montar os formulários */
    public static function all(): array
    {
        $map = static fn (array $a): array => array_map(static fn ($k, $v) => ['value' => (string) $k, 'label' => $v], array_keys($a), $a);

        return [
            'port_kinds' => $map(self::PORT_KINDS),
            'port_types' => array_map($map, self::PORT_TYPES),
            'non_connectable' => self::NON_CONNECTABLE,
            'compatible' => self::COMPATIBLE,
            'cable_types' => $map(self::CABLE_TYPES),
            'cable_status' => $map(self::CABLE_STATUS),
            'length_units' => $map(self::LENGTH_UNITS),
            'colors' => $map(self::COLORS),
            'device_status' => $map(self::DEVICE_STATUS),
            'rack_status' => $map(self::RACK_STATUS),
            'location_kinds' => $map(self::LOCATION_KINDS),
            'rack_widths' => self::RACK_WIDTHS,
            'role_icons' => $map(self::ROLE_ICONS),
        ];
    }

    /** @return list<string> */
    public static function keys(string $const): array
    {
        /** @var array<string, string> $a */
        $a = \constant(self::class.'::'.$const);

        return array_map('strval', array_keys($a));
    }
}
