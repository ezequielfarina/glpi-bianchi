<?php

declare(strict_types=1);

namespace App\Vpn\Import;

/**
 * Da árvore do FortiOS para os túneis IPsec documentáveis (ADR-0022):
 *  - `vpn ipsec phase1-interface` / `phase1` (modo interface / policy) e as fases 2 de cada um;
 *  - seletores da fase 2 resolvidos para CIDR (subnet, ip, range ou objeto/grupo de endereço);
 *  - IP local (local-gw ou IP da interface WAN), IP do túnel (interface de mesmo nome), rotas estáticas
 *    pelo túnel e zona SD-WAN;
 *  - VDOMs (`config vdom` → `edit <vdom>`), interfaces em `config global` no modo multi-VDOM.
 *
 * SEGURANÇA: parâmetro com nome de segredo (psksecret, ppk-secret, senha, chave privada) ou valor `ENC …` é
 * descartado aqui e só contado — nunca chega ao banco, ao log ou à resposta.
 */
final class FortiVpnExtractor
{
    private const string SECRET_KEY = '/psk|ppk|secret|passw|private-key|authpwd|^key$|-key$|xauth.*pass/i';

    private int $removed = 0;

    /**
     * @param array{tree: array<string, mixed>, header: array<string, string>} $parsed
     *
     * @return array{hostname: ?string, model: ?string, version: ?string, secrets_removed: int, tunnels: list<array<string, mixed>>, warnings: list<string>}
     */
    public function extract(array $parsed): array
    {
        $this->removed = 0;
        $root = $parsed['tree'];
        $global = $root['config']['global'] ?? null;
        $hostname = self::first(($global['config']['system global'] ?? $root['config']['system global'] ?? null)['set']['hostname'] ?? null) ?? ($parsed['header']['prompt'] ?? null);
        [$model, $version] = self::header($parsed['header']['config-version'] ?? null);

        $contexts = [];
        if (isset($root['config']['vdom']['edit']) && [] !== $root['config']['vdom']['edit']) {
            foreach ($root['config']['vdom']['edit'] as $vdom => $node) {
                $contexts[(string) $vdom] = $node;
            }
        } else {
            $contexts[''] = $root;
        }
        $globalIfaces = $global['config']['system interface']['edit'] ?? $root['config']['system interface']['edit'] ?? [];

        $tunnels = [];
        $warnings = [];
        foreach ($contexts as $vdom => $ctx) {
            $ifaces = ($ctx['config']['system interface']['edit'] ?? []) + $globalIfaces;
            $addr = new AddressBook(
                $ctx['config']['firewall address']['edit'] ?? [],
                $ctx['config']['firewall addrgrp']['edit'] ?? [],
                $ctx['config']['firewall address6']['edit'] ?? [],
                $ctx['config']['firewall addrgrp6']['edit'] ?? []
            );
            $routes = $this->routes($ctx, $addr);
            $zones = $this->sdwan($ctx);
            foreach (['interface' => 'vpn ipsec phase1-interface', 'policy' => 'vpn ipsec phase1'] as $mode => $p1Path) {
                $p2Path = 'interface' === $mode ? 'vpn ipsec phase2-interface' : 'vpn ipsec phase2';
                $p2ByP1 = [];
                foreach ($ctx['config'][$p2Path]['edit'] ?? [] as $name => $p2) {
                    $p1 = self::first($p2['set']['phase1name'] ?? null);
                    if (null !== $p1) {
                        $p2ByP1[$p1][] = $this->phase2((string) $name, $p2, $addr);
                    }
                }
                foreach ($ctx['config'][$p1Path]['edit'] ?? [] as $name => $p1) {
                    $name = (string) $name;
                    $tunnels[] = $this->phase1($name, (string) $vdom, $mode, $p1, $ifaces, $routes[$name] ?? [], $zones[$name] ?? null, $p2ByP1[$name] ?? []);
                    unset($p2ByP1[$name]);
                }
                foreach (array_keys($p2ByP1) as $orphan) {
                    $warnings[] = \sprintf('Fase 2 aponta para a fase 1 "%s", que não está no texto colado (inclua a fase 1).', $orphan);
                }
            }
        }
        if ([] === $tunnels) {
            $warnings[] = 'Nenhum túnel IPsec encontrado. Cole a saída de "show vpn ipsec phase1-interface" e "show vpn ipsec phase2-interface" (ou o backup .conf).';
        }

        return ['hostname' => $hostname, 'model' => $model, 'version' => $version, 'secrets_removed' => $this->removed, 'tunnels' => $tunnels, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed>       $p1
     * @param array<string, mixed>       $ifaces
     * @param list<string>               $routes
     * @param list<array<string, mixed>> $phase2
     *
     * @return array<string, mixed>
     */
    private function phase1(string $name, string $vdom, string $mode, array $p1, array $ifaces, array $routes, ?string $zone, array $phase2): array
    {
        $params = $this->clean($p1['set'] ?? []);
        $s = static fn (string $k): ?string => $params[$k] ?? null;
        $type = $s('type') ?? 'static';
        $remote = 'ddns' === $type ? $s('remotegw-ddns') : ('dynamic' === $type ? null : ($s('remote-gw') ?? $s('remote-gw6')));
        if ('0.0.0.0' === $remote) {
            $remote = null;
        }
        $topology = match (true) {
            'dynamic' === $type && 'enable' === $s('auto-discovery-sender') => 'hub',
            'dynamic' === $type => 'dialup',
            'enable' === $s('auto-discovery-receiver') => 'spoke',
            default => 'site_to_site',
        };
        $wan = $s('interface');
        $localGw = $s('local-gw');
        $localIp = null !== $localGw && '0.0.0.0' !== $localGw ? $localGw : self::ifaceIp($ifaces[(string) $wan] ?? null, 'ip');
        $tun = $ifaces[$name] ?? null;
        $ike = $s('ike-version');

        $core = ['type', 'interface', 'ike-version', 'mode', 'authmethod', 'proposal', 'dhgrp', 'keylife', 'remote-gw', 'remote-gw6', 'remotegw-ddns',
            'local-gw', 'dpd', 'nattraversal', 'comments'];

        return [
            'name' => $name, 'vdom' => $vdom, 'mode' => $mode, 'topology' => $topology,
            'local_interface' => $wan, 'local_ip' => $localIp, 'remote_gw' => $remote,
            'ike_version' => null === $ike ? 1 : (int) $ike, 'ike_mode' => '2' === $ike ? null : ($s('mode') ?? 'main'),
            'auth_method' => $s('authmethod') ?? 'psk', 'p1_proposal' => $s('proposal'), 'p1_dhgroups' => $s('dhgrp'),
            'p1_keylife' => null === $s('keylife') ? null : (int) $s('keylife'), 'dpd' => $s('dpd'), 'nat_traversal' => $s('nattraversal'),
            'tunnel_ip' => self::ifaceIp($tun, 'ip', true), 'tunnel_remote_ip' => self::ifaceIp($tun, 'remote-ip', true),
            'routes' => $routes, 'sdwan_zone' => $zone, 'description' => $s('comments'),
            'params' => array_diff_key($params, array_flip($core)),
            'phase2' => $phase2,
        ];
    }

    /**
     * @param array<string, mixed> $p2
     *
     * @return array<string, mixed>
     */
    private function phase2(string $name, array $p2, AddressBook $addr): array
    {
        $params = $this->clean($p2['set'] ?? []);
        $s = static fn (string $k): ?string => $params[$k] ?? null;
        $bool = static fn (?string $v): ?bool => null === $v ? null : 'enable' === $v;
        $sel = static function (string $side) use ($params, $addr): array {
            $v6 = isset($params[$side.'-subnet6']) || isset($params[$side.'-name6']) || str_ends_with((string) ($params[$side.'-addr-type'] ?? ''), '6');
            $type = $params[$side.'-addr-type'] ?? ($v6 ? 'subnet6' : 'subnet');

            return match (rtrim($type, '6')) {
                'name' => [$addr->resolve((string) ($params[$side.'-name'.($v6 ? '6' : '')] ?? '')), $params[$side.'-name'.($v6 ? '6' : '')] ?? null],
                'ip' => [[Cidr::from((string) ($params[$side.'-start-ip'.($v6 ? '6' : '')] ?? '0.0.0.0'))], null],
                'range' => [[($params[$side.'-start-ip'.($v6 ? '6' : '')] ?? '?').'-'.($params[$side.'-end-ip'.($v6 ? '6' : '')] ?? '?')], null],
                default => [[Cidr::from((string) ($params[$side.'-subnet'.($v6 ? '6' : '')] ?? ($v6 ? '::/0' : '0.0.0.0 0.0.0.0')))], null],
            };
        };
        [$src, $srcName] = $sel('src');
        [$dst, $dstName] = $sel('dst');
        $core = ['phase1name', 'proposal', 'pfs', 'dhgrp', 'keylifeseconds', 'auto-negotiate', 'comments'];

        return [
            'name' => $name, 'proposal' => $s('proposal'), 'pfs' => $bool($s('pfs')), 'dhgroups' => $s('dhgrp'),
            'keylife_seconds' => null === $s('keylifeseconds') ? null : (int) $s('keylifeseconds'), 'auto_negotiate' => $bool($s('auto-negotiate')),
            'src' => array_values(array_unique($src)), 'dst' => array_values(array_unique($dst)), 'src_name' => $srcName, 'dst_name' => $dstName,
            'params' => array_filter(array_diff_key($params, array_flip($core)), static fn (string $k): bool => !preg_match('/^(src|dst)-/', $k), \ARRAY_FILTER_USE_KEY),
        ];
    }

    /**
     * Rotas estáticas por túnel: `config router static` com `set device "<túnel>"`.
     *
     * @param array<string, mixed> $ctx
     *
     * @return array<string, list<string>>
     */
    private function routes(array $ctx, AddressBook $addr): array
    {
        $out = [];
        foreach (['router static' => '0.0.0.0 0.0.0.0', 'router static6' => '::/0'] as $path => $default) {
            foreach ($ctx['config'][$path]['edit'] ?? [] as $r) {
                $set = $r['set'] ?? [];
                $dev = self::first($set['device'] ?? null);
                if (null === $dev || 'enable' === self::first($set['blackhole'] ?? null) || 'disable' === self::first($set['status'] ?? null)) {
                    continue;
                }
                $nets = isset($set['dstaddr']) ? $addr->resolve((string) self::first($set['dstaddr'])) : [Cidr::from(implode(' ', $set['dst'] ?? [$default]))];
                $out[$dev] = array_values(array_unique([...($out[$dev] ?? []), ...$nets]));
            }
        }

        return $out;
    }

    /**
     * Zona SD-WAN de cada interface membro (`system sdwan` ou o antigo `system virtual-wan-link`).
     *
     * @param array<string, mixed> $ctx
     *
     * @return array<string, string>
     */
    private function sdwan(array $ctx): array
    {
        $out = [];
        foreach (['system sdwan', 'system virtual-wan-link'] as $path) {
            foreach ($ctx['config'][$path]['config']['members']['edit'] ?? [] as $m) {
                $if = self::first($m['set']['interface'] ?? null);
                if (null !== $if) {
                    $out[$if] = self::first($m['set']['zone'] ?? null) ?? 'virtual-wan-link';
                }
            }
        }

        return $out;
    }

    /**
     * "set" sem segredos: chave/valor em texto. Valor `ENC …` ou chave sensível → descartado e contado.
     *
     * @param array<string, list<string>> $set
     *
     * @return array<string, string>
     */
    private function clean(array $set): array
    {
        $out = [];
        foreach ($set as $k => $vals) {
            if (preg_match(self::SECRET_KEY, $k) || 'ENC' === strtoupper($vals[0] ?? '') || str_contains(implode(' ', $vals), '-----BEGIN')) {
                ++$this->removed;
                continue;
            }
            $out[$k] = mb_substr(implode(' ', $vals), 0, 500);
        }

        return $out;
    }

    /** @param array<string, mixed>|null $iface */
    private static function ifaceIp(?array $iface, string $key, bool $withMask = false): ?string
    {
        $v = $iface['set'][$key] ?? null;
        if (!\is_array($v) || [] === $v || '0.0.0.0' === $v[0]) {
            return null;
        }

        return $withMask ? Cidr::from(implode(' ', $v)) : explode('/', $v[0])[0];
    }

    /** @return array{0: ?string, 1: ?string} modelo e versão do cabeçalho "#config-version=FG100F-7.2.5-FW-build1517-…" */
    private static function header(?string $v): array
    {
        if (null === $v || 1 !== preg_match('/^([A-Z0-9]+)-(\d+\.\d+\.\d+)/i', $v, $m)) {
            return [null, null];
        }

        return [$m[1], $m[2]];
    }

    private static function first(mixed $v): ?string
    {
        return \is_array($v) && isset($v[0]) && \is_string($v[0]) && '' !== $v[0] ? $v[0] : null;
    }
}
