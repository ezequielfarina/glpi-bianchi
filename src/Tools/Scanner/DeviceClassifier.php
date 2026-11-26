<?php

declare(strict_types=1);

namespace App\Tools\Scanner;

/**
 * Palpite do tipo de dispositivo a partir do que o nmap viu: classe de SO, tipo de
 * serviço, fabricante do MAC, portas abertas e nomes. Ordem das regras importa
 * (impressora com servidor web continua impressora).
 *
 * Tipos: computer, server, printer, network_equipment, phone, mobile, camera, iot, unknown.
 */
final class DeviceClassifier
{
    /** tipo do scanner → código do tipo de ativo no inventário */
    public const array ASSET_TYPE = [
        'computer' => 'computer', 'server' => 'computer', 'printer' => 'printer',
        'network_equipment' => 'network_equipment', 'phone' => 'phone', 'mobile' => 'phone',
        'camera' => 'peripheral', 'iot' => 'peripheral', 'unknown' => 'peripheral',
    ];

    private const string NET_VENDORS = '/ubiquiti|mikrotik|routerboard|fortinet|cisco|aruba|juniper|tp-?link|huawei technologies|ruckus|d-?link|netgear|zyxel|meraki|palo alto|sophos|draytek|cambium|intelbras.*(switch|router)/i';
    private const string PRINTER_VENDORS = '/hewlett|\bhp\b|brother|epson|kyocera|lexmark|ricoh|xerox|canon|samsung electronics.*print|oki data|konica|sharp|zebra|elgin|bematech/i';
    private const string PHONE_VENDORS = '/yealink|grandstream|polycom|snom|fanvil|avaya|mitel|htek|khomp/i';
    private const string CAMERA_VENDORS = '/hikvision|dahua|axis communications|hanwha|vivotek|uniview|intelbras/i';
    private const string MOBILE_VENDORS = '/apple|samsung|xiaomi|motorola|oneplus|oppo|vivo|realme|google|lg electronics|zebra technologies/i';
    private const string IOT_VENDORS = '/espressif|tuya|raspberry|shelly|sonoff|amazon technologies|google nest|philips lighting|chromecast/i';

    /**
     * @param array{ports: list<array<string, mixed>>, os: string|null, vendor: string|null, hostname: string|null, netbios_name: string|null, hints: array<string, list<string>>} $h
     */
    public function classify(array $h): string
    {
        $ports = array_map(static fn (array $p): int => (int) $p['port'], $h['ports']);
        $has = static fn (int ...$any): bool => [] !== array_intersect($any, $ports);
        $types = strtolower(implode(' ', $h['hints']['types'] ?? []));
        $vendor = implode(' ', array_filter([$h['vendor'], ...($h['hints']['vendors'] ?? [])]));
        $os = strtolower((string) $h['os']);
        $services = strtolower(implode(' ', array_map(static fn (array $p): string => ($p['service'] ?? '').' '.($p['product'] ?? ''), $h['ports'])));

        if (str_contains($types, 'printer') || $has(9100, 515) || ($has(631) && 1 === preg_match(self::PRINTER_VENDORS, $vendor))
            || (1 === preg_match(self::PRINTER_VENDORS, $vendor) && $has(80, 443, 161) && !str_contains($os, 'windows'))) {
            return 'printer';
        }
        if (1 === preg_match('/router|switch|firewall|wap|bridge|load balancer|broadband/', $types) || $has(8291, 8728)
            || str_contains($os, 'routeros') || str_contains($os, 'fortios') || str_contains($os, 'cisco ios')
            || (1 === preg_match(self::NET_VENDORS, $vendor) && !$has(3389, 445))) {
            return 'network_equipment';
        }
        if (str_contains($types, 'phone') && !str_contains($os, 'android') || 1 === preg_match(self::PHONE_VENDORS, $vendor) || ($has(5060, 5061) && !$has(3389, 445, 22))) {
            return 'phone';
        }
        if (str_contains($types, 'webcam') || 1 === preg_match(self::CAMERA_VENDORS, $vendor) && $has(554, 8000, 37777, 80) || ($has(554) && !$has(445))) {
            return 'camera';
        }
        if (str_contains($os, 'windows') || $has(3389, 445, 135) || null !== $h['netbios_name']) {
            $isServer = str_contains($os, 'server') || $has(88, 389, 1433, 53) && $has(445);

            return $isServer ? 'server' : 'computer';
        }
        if (str_contains($os, 'esxi') || str_contains($os, 'proxmox') || $has(902, 8006)) {
            return 'server';
        }
        if (str_contains($os, 'ios') || str_contains($os, 'android') || (1 === preg_match(self::MOBILE_VENDORS, $vendor) && \count($ports) <= 2)) {
            return 'mobile';
        }
        if (1 === preg_match(self::IOT_VENDORS, $vendor) || $has(1883)) {
            return 'iot';
        }
        if (1 === preg_match('/linux|unix|freebsd|bsd/', $os.' '.$services) && $has(22)) {
            return $has(80, 443, 3306, 5432, 25, 53, 2049, 6379) ? 'server' : 'computer';
        }
        if ([] !== $ports) {
            return $has(22, 80, 443) ? 'server' : 'computer';
        }

        return 'unknown';
    }
}
