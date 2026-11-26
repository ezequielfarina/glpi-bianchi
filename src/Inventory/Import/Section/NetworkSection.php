<?php

declare(strict_types=1);

namespace App\Inventory\Import\Section;

use App\Inventory\Import\ImportContext;
use App\Inventory\Import\Value;
use App\Network\Entity\IpAddress;
use App\Network\Entity\NetworkPort;

/**
 * `networks[]` → NetworkPort + IpAddress.
 *
 * O agente envia uma entrada por (interface, IP): uma NIC com IPv4 e IPv6 vem
 * duas vezes. Agrupamos por (descrição, MAC) antes de sincronizar.
 * Loopback é descartado (mesma regra padrão do GLPI).
 */
final readonly class NetworkSection implements SectionImporter
{
    public function sections(): array
    {
        return ['networks'];
    }

    public static function priority(): int
    {
        return 30;
    }

    public function import(array $content, ImportContext $context): void
    {
        $asset = $context->asset;

        /** @var array<string, array{name: string, mac: ?string, type: ?string, speed: ?int, status: ?string, virtual: bool, ips: array<string, ?int>, attrs: array<string, mixed>}> $groups */
        $groups = [];
        foreach (Value::list($content['networks'] ?? null) as $n) {
            $type = mb_strtolower(Value::str($n['type'] ?? null) ?? '');
            $name = Value::str($n['description'] ?? null) ?? Value::str($n['name'] ?? null) ?? 'eth';
            if ('loopback' === $type || 'lo' === $name) {
                continue;
            }
            $mac = NetworkPort::normalizeMac(Value::str($n['macaddr'] ?? null, 32));
            $key = ($mac ?? '').'|'.mb_strtolower($name);

            $groups[$key] ??= [
                'name' => $name,
                'mac' => $mac,
                'type' => '' === $type ? null : $type,
                'speed' => Value::int($n['speed'] ?? null),
                'status' => Value::str($n['status'] ?? null, 16),
                'virtual' => true === ($n['virtualdev'] ?? false) || '1' === ($n['virtualdev'] ?? null),
                'ips' => [],
                'attrs' => [
                    'driver' => Value::str($n['driver'] ?? null),
                    'pci_id' => Value::str($n['pciid'] ?? null),
                    'wifi_ssid' => Value::str($n['wifi_ssid'] ?? null),
                    'dhcp_server' => Value::str($n['ipdhcp'] ?? null),
                    'gateway' => Value::str($n['ipgateway'] ?? null),
                ],
            ];

            if (null !== ($ip4 = Value::str($n['ipaddress'] ?? null, 45))) {
                $groups[$key]['ips'][$ip4] = IpAddress::maskToPrefix(Value::str($n['ipmask'] ?? null, 45));
            }
            if (null !== ($ip6 = Value::str($n['ipaddress6'] ?? null, 45))) {
                $groups[$key]['ips'][$ip6] = Value::int($n['ipmask6'] ?? null);
            }
        }

        $existing = [];
        foreach ($asset->getNetworkPorts() as $port) {
            $existing[$port->reconciliationKey()] = $port;
        }

        foreach ($groups as $key => $g) {
            $port = $existing[$key] ?? null;
            unset($existing[$key]);

            if (null === $port) {
                $port = new NetworkPort($asset, $g['name']);
                $port->setMacAddress($g['mac']);
                $port->setDynamic(true);
                $asset->addNetworkPort($port);
                $context->event('network_port.added', null, trim($g['name'].' '.($g['mac'] ?? '')));
            }

            $port->setType($g['type']);
            $port->setSpeedMbps($g['speed']);
            $port->setOperStatus($g['status']);
            $port->setVirtual($g['virtual']);
            $port->setAttributes($g['attrs']);
            $port->syncIpAddresses($g['ips']);
        }

        foreach ($existing as $old) {
            if ($old->isDynamic()) {
                $asset->removeNetworkPort($old);
                $context->event('network_port.removed', trim($old->getName().' '.($old->getMacAddress() ?? '')), null);
            }
        }
    }
}
