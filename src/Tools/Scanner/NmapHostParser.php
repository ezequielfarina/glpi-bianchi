<?php

declare(strict_types=1);

namespace App\Tools\Scanner;

/**
 * Extrai de um `<host>` do nmap tudo o que for útil: IP, MAC/fabricante, nomes (DNS,
 * NetBIOS, SMB, RDP), SO, portas/serviços/versões e saídas dos scripts NSE.
 */
final class NmapHostParser
{
    /**
     * @return array{ip: string, state: string, mac: string|null, mac_source: string|null, vendor: string|null,
     *   hostname: string|null, netbios_name: string|null, domain: string|null, os: string|null, os_accuracy: int|null,
     *   latency_ms: int|null, ports: list<array<string, mixed>>, details: array<string, mixed>, hints: array<string, list<string>>}|null
     */
    public function parse(\SimpleXMLElement $host): ?array
    {
        if ('up' !== (string) ($host->status['state'] ?? '')) {
            return null;
        }
        $ip = null;
        $mac = null;
        $vendor = null;
        foreach ($host->address as $a) {
            if ('ipv4' === (string) $a['addrtype']) {
                $ip = (string) $a['addr'];
            } elseif ('mac' === (string) $a['addrtype']) {
                $mac = strtolower((string) $a['addr']);
                $vendor = '' !== (string) $a['vendor'] ? (string) $a['vendor'] : null;
            }
        }
        if (null === $ip) {
            return null;
        }
        $hostname = null;
        foreach ($host->hostnames->hostname ?? [] as $h) {
            $hostname ??= (string) $h['name'];
        }

        $details = ['reason' => (string) ($host->status['reason'] ?? '')];
        $hints = ['types' => [], 'vendors' => []];
        if (null !== $vendor) {
            $hints['vendors'][] = $vendor;
        }

        // ---- portas
        $ports = [];
        foreach ($host->ports->port ?? [] as $p) {
            $state = (string) $p->state['state'];
            if ('open' !== $state) {
                continue;
            }
            $svc = $p->service;
            $port = [
                'port' => (int) $p['portid'],
                'proto' => (string) $p['protocol'],
                'service' => self::str($svc['name'] ?? null),
                'product' => self::str($svc['product'] ?? null),
                'version' => self::str($svc['version'] ?? null),
                'extra' => self::str($svc['extrainfo'] ?? null),
                'tunnel' => self::str($svc['tunnel'] ?? null),
            ];
            if (null !== ($dt = self::str($svc['devicetype'] ?? null))) {
                $hints['types'][] = $dt;
            }
            if (null !== ($ot = self::str($svc['ostype'] ?? null))) {
                $details['service_os'][] = $ot;
            }
            foreach ($p->script ?? [] as $sc) {
                $id = (string) $sc['id'];
                $out = trim((string) $sc['output']);
                match ($id) {
                    'http-title' => str_starts_with($out, "Site doesn't have a title") ? null : $port['title'] = mb_substr($out, 0, 200),
                    'http-server-header' => $port['server'] = mb_substr($out, 0, 200),
                    'http-generator' => $port['generator'] = mb_substr($out, 0, 200),
                    'ssl-cert' => $port['cert'] = self::cert($sc),
                    'snmp-sysdescr' => $details['snmp_sysdescr'] = mb_substr($out, 0, 500),
                    default => $port['scripts'][$id] = mb_substr($out, 0, 2000),
                };
            }
            $ports[] = array_filter($port, static fn ($v) => null !== $v);
        }

        // ---- SO
        $os = null;
        $osAccuracy = null;
        foreach ($host->os->osmatch ?? [] as $om) {
            $os = (string) $om['name'];
            $osAccuracy = (int) $om['accuracy'];
            foreach ($om->osclass as $oc) {
                $hints['types'][] = (string) $oc['type'];
                $hints['vendors'][] = (string) $oc['vendor'];
                $details['os_family'] ??= trim((string) $oc['osfamily'].' '.(string) $oc['osgen']);
            }
            break; // melhor palpite
        }

        // ---- scripts do host
        $netbios = null;
        $domain = null;
        foreach ($host->hostscript->script ?? [] as $sc) {
            $id = (string) $sc['id'];
            $out = trim((string) $sc['output']);
            $elems = self::elems($sc);
            switch ($id) {
                case 'nbstat':
                    if (1 === preg_match('/NetBIOS name:\s*([^,\s]+)/', $out, $m)) {
                        $netbios = $m[1];
                    }
                    if (1 === preg_match('/NetBIOS user:\s*([^,]+)/', $out, $m) && !str_contains($m[1], 'unknown')) {
                        $details['netbios_user'] = trim($m[1]);
                    }
                    if (null === $mac && 1 === preg_match('/NetBIOS MAC:\s*([0-9a-f]{2}(?::[0-9a-f]{2}){5})/i', $out, $m) && '00:00:00:00:00:00' !== $m[1]) {
                        $mac = strtolower($m[1]);
                        $details['mac_from'] = 'nbstat';
                    }
                    break;
                case 'smb-os-discovery':
                    $details['smb'] = $elems;
                    $os ??= $elems['os'] ?? null;
                    $netbios ??= $elems['NetBIOS computer name'] ?? $elems['server'] ?? null;
                    $domain ??= $elems['domain_dns'] ?? $elems['workgroup'] ?? null;
                    $hostname ??= $elems['fqdn'] ?? null;
                    break;
                case 'rdp-ntlm-info':
                    $details['rdp'] = $elems;
                    $netbios ??= $elems['NetBIOS_Computer_Name'] ?? null;
                    $domain ??= $elems['DNS_Domain_Name'] ?? null;
                    $hostname ??= $elems['DNS_Computer_Name'] ?? null;
                    if (isset($elems['Product_Version'])) {
                        $details['windows_build'] = $elems['Product_Version'];
                    }
                    break;
                default:
                    $details['scripts'][$id] = mb_substr($out, 0, 2000);
            }
        }
        if (isset($details['snmp_sysdescr'])) {
            $os ??= mb_substr((string) $details['snmp_sysdescr'], 0, 255);
        }
        $latency = isset($host->times['srtt']) ? (int) round(((int) $host->times['srtt']) / 1000) : null;
        if (isset($host->distance['value'])) {
            $details['hops'] = (int) $host->distance['value'];
        }

        return [
            'ip' => $ip,
            'state' => 'up',
            'mac' => $mac,
            'mac_source' => null === $mac ? null : ($details['mac_from'] ?? 'arp'),
            'vendor' => $vendor,
            'hostname' => null === $hostname ? null : mb_substr($hostname, 0, 255),
            'netbios_name' => null === $netbios ? null : mb_substr($netbios, 0, 64),
            'domain' => null === $domain ? null : mb_substr($domain, 0, 255),
            'os' => null === $os ? null : mb_substr($os, 0, 255),
            'os_accuracy' => $osAccuracy,
            'latency_ms' => $latency,
            'ports' => $ports,
            'details' => $details,
            'hints' => $hints,
        ];
    }

    /** @return array<string, string> */
    private static function elems(\SimpleXMLElement $script): array
    {
        $out = [];
        foreach ($script->elem as $e) {
            $out[(string) $e['key']] = (string) $e;
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function cert(\SimpleXMLElement $script): array
    {
        $c = [];
        foreach ($script->table as $t) {
            $key = (string) $t['key'];
            if (\in_array($key, ['subject', 'issuer'], true)) {
                foreach ($t->elem as $e) {
                    if ('commonName' === (string) $e['key']) {
                        $c[$key] = (string) $e;
                    }
                }
            }
            if ('validity' === $key) {
                foreach ($t->elem as $e) {
                    if ('notAfter' === (string) $e['key']) {
                        $c['expires'] = (string) $e;
                    }
                }
            }
        }

        return $c;
    }

    private static function str(mixed $v): ?string
    {
        $s = trim((string) $v);

        return '' === $s ? null : $s;
    }
}
