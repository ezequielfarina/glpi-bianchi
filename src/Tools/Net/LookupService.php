<?php

declare(strict_types=1);

namespace App\Tools\Net;

use App\Tools\Scanner\MacVendors;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ferramentas → Consultas (ADR-0013):
 * - DNS com `dig`, comparando vários servidores lado a lado (diagnóstico de split-horizon/AD × público);
 * - ASN/prefixo BGP pela Team Cymru **via DNS** (não depende da porta 43);
 * - registro (whois moderno) por **RDAP** (HTTPS, rdap.org → RIR/registro.br);
 * - MAC: fabricante (MA-L/M/S), flags e onde aparece no inventário e nas varreduras.
 */
final readonly class LookupService
{
    public const array DNS_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SOA', 'SRV', 'PTR', 'CAA', 'ANY'];

    public function __construct(
        private Connection $db,
        private MacVendors $vendors,
        private HttpClientInterface $http,
    ) {
    }

    /** @return list<string> resolvers do próprio servidor (/etc/resolv.conf) */
    public static function systemResolvers(): array
    {
        $out = [];
        foreach (@file('/etc/resolv.conf', \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (1 === preg_match('/^\s*nameserver\s+(\S+)/', $line, $m) && Host::isIp($m[1])) {
                $out[] = $m[1];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $servers IPs; vazio = resolver do sistema
     *
     * @return array<string, mixed>
     */
    public function dns(string $nameInput, string $type, array $servers): array
    {
        $type = strtoupper($type);
        if (!\in_array($type, self::DNS_TYPES, true)) {
            throw new ValidationException(['type' => 'Tipo de registro não suportado.']);
        }
        try {
            $name = Host::parse($nameInput);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(['name' => $e->getMessage()]);
        }
        $servers = array_values(array_unique(array_filter(array_map('trim', $servers), static fn (string $s): bool => '' !== $s)));
        if (\count($servers) > 5) {
            throw new ValidationException(['servers' => 'Compare no máximo 5 servidores.']);
        }
        foreach ($servers as $s) {
            if (!Host::isIp($s)) {
                throw new ValidationException(['servers' => "Servidor DNS inválido: {$s} (use o IP)."]);
            }
        }
        if (!Command::exists('dig')) {
            throw new ValidationException(['name' => 'dig não instalado no servidor (apt install bind9-dnsutils).']);
        }
        $reverse = Host::isIp($name);
        if ($reverse) {
            $type = 'PTR';
        }
        $results = [];
        foreach ([] === $servers ? [null] : $servers as $server) {
            $argv = ['dig', '+time=2', '+tries=1', '+noall', '+comments', '+answer', '+authority', '+stats'];
            if (null !== $server) {
                $argv[] = '@'.$server;
            }
            array_push($argv, ...($reverse ? ['-x', $name] : [$name, $type]));
            $results[] = ['server' => $server ?? 'sistema'] + self::parseDig(Command::run($argv, 10)['stdout']);
        }
        // respostas divergentes entre servidores (split-horizon, propagação, cache)
        $sets = array_map(static fn (array $r): string => implode('|', array_map(static fn (array $a): string => $a['type'].' '.$a['data'], $r['answers'])), $results);

        return ['name' => $name, 'type' => $type, 'results' => $results, 'divergent' => \count(array_unique($sets)) > 1];
    }

    /** @return array{status: string, flags: list<string>, answers: list<array<string, mixed>>, authority: list<array<string, mixed>>, ms: int|null, via: string|null} */
    public static function parseDig(string $out): array
    {
        $status = 'SEM RESPOSTA';
        $flags = [];
        $ms = null;
        $via = null;
        $section = null;
        $answers = $authority = [];
        foreach (explode("\n", $out) as $line) {
            if (1 === preg_match('/status: ([A-Z]+)/', $line, $m)) {
                $status = $m[1];
            } elseif (1 === preg_match('/^;; flags: ([a-z ]+);/', $line, $m)) {
                $flags = array_values(array_filter(explode(' ', trim($m[1]))));
            } elseif (1 === preg_match('/^;; Query time: (\d+) msec/', $line, $m)) {
                $ms = (int) $m[1];
            } elseif (1 === preg_match('/^;; SERVER: ([^#]+)#/', $line, $m)) {
                $via = $m[1];
            } elseif (str_contains($line, 'ANSWER SECTION')) {
                $section = 'answer';
            } elseif (str_contains($line, 'AUTHORITY SECTION')) {
                $section = 'authority';
            } elseif (str_contains($line, 'timed out') || str_contains($line, 'no servers could be reached')) {
                $status = 'TIMEOUT';
            } elseif ('' !== trim($line) && !str_starts_with($line, ';') && null !== $section) {
                $p = preg_split('/\s+/', trim($line), 5);
                if (\is_array($p) && 5 === \count($p)) {
                    $rr = ['name' => $p[0], 'ttl' => (int) $p[1], 'type' => $p[3], 'data' => $p[4]];
                    if ('answer' === $section) {
                        $answers[] = $rr;
                    } else {
                        $authority[] = $rr;
                    }
                }
            }
        }

        return ['status' => $status, 'flags' => $flags, 'answers' => $answers, 'authority' => $authority, 'ms' => $ms, 'via' => $via];
    }

    /**
     * IP (ou nome) → ASN de origem, prefixo BGP anunciado, país, RIR e nome do AS (Team Cymru via DNS TXT).
     *
     * @return array<string, mixed>
     */
    public function asn(string $input): array
    {
        try {
            $host = Host::parse($input);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(['query' => $e->getMessage()]);
        }
        $ip = Host::isIp($host) ? $host : (gethostbyname($host) !== $host ? gethostbyname($host) : null);
        if (null === $ip) {
            throw new ValidationException(['query' => 'Nome não resolve no DNS do servidor.']);
        }
        if (null !== ($special = Host::specialRange($ip))) {
            return ['ip' => $ip, 'special' => $special, 'asn' => null];
        }
        if (str_contains($ip, ':')) {
            $hex = bin2hex((string) inet_pton($ip));
            $q = implode('.', array_reverse(str_split($hex))).'.origin6.asn.cymru.com';
        } else {
            $q = implode('.', array_reverse(explode('.', $ip))).'.origin.asn.cymru.com';
        }
        $origin = $this->txt($q);
        if (null === $origin) {
            return ['ip' => $ip, 'special' => null, 'asn' => null, 'error' => 'Sem anúncio BGP conhecido (ou DNS externo bloqueado no servidor).'];
        }
        // "15169 | 8.8.8.0/24 | US | arin | 2023-12-28" (pode vir "13335 15169 | ...")
        $f = array_map('trim', explode('|', $origin));
        $asns = array_values(array_filter(explode(' ', $f[0])));
        $names = [];
        foreach ($asns as $a) {
            $d = $this->txt('AS'.$a.'.asn.cymru.com');
            $names[$a] = null === $d ? null : trim((string) (explode('|', $d)[4] ?? ''));
        }

        return [
            'ip' => $ip, 'special' => null,
            'asn' => array_map(static fn (string $a): array => ['number' => (int) $a, 'name' => $names[$a]], $asns),
            'prefix' => $f[1] ?? null, 'country' => $f[2] ?? null, 'registry' => $f[3] ?? null, 'allocated' => ($f[4] ?? '') ?: null,
        ];
    }

    /**
     * RDAP (RFC 9083) — o "whois" estruturado: domínio, IP/bloco ou ASN.
     *
     * @return array<string, mixed>
     */
    public function rdap(string $input): array
    {
        $q = trim($input);
        if (1 === preg_match('/^AS?(\d{1,10})$/i', $q, $m)) {
            $path = 'autnum/'.$m[1];
        } elseif (Host::isIp($q)) {
            if (null !== ($special = Host::specialRange($q))) {
                return ['query' => $q, 'kind' => 'ip', 'special' => $special];
            }
            $path = 'ip/'.$q;
        } elseif (Host::isName($q) && str_contains($q, '.')) {
            $path = 'domain/'.strtolower(rtrim($q, '.'));
        } else {
            throw new ValidationException(['query' => 'Informe um domínio, IP ou ASN (ex.: AS28573).']);
        }
        try {
            $resp = $this->http->request('GET', 'https://rdap.org/'.$path, [
                'headers' => ['Accept' => 'application/rdap+json, application/json'],
                'timeout' => 10, 'max_duration' => 20, 'max_redirects' => 5,
            ]);
            $code = $resp->getStatusCode();
            if (404 === $code) {
                return ['query' => $q, 'kind' => strtok($path, '/'), 'special' => null, 'found' => false];
            }
            $data = $resp->toArray(false);
            $url = (string) ($resp->getInfo('url') ?? '');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            throw new ValidationException(['query' => str_contains(strtolower($msg), 'ssl') || str_contains(strtolower($msg), 'certificate')
                ? 'Falha TLS ao consultar o RDAP. Se a rede faz inspeção SSL no FortiGate, instale a CA do FortiGate no CT (update-ca-certificates).'
                : 'RDAP indisponível a partir do servidor: '.mb_substr($msg, 0, 200)]);
        }

        return ['query' => $q, 'kind' => strtok($path, '/'), 'special' => null, 'found' => true, 'source' => parse_url($url, \PHP_URL_HOST) ?: null]
            + self::summarizeRdap($data)
            + ['raw' => mb_substr((string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 0, 200_000)];
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public static function summarizeRdap(array $d): array
    {
        $events = [];
        foreach ($d['events'] ?? [] as $ev) {
            $events[(string) ($ev['eventAction'] ?? '')] = $ev['eventDate'] ?? null;
        }
        $cidrs = [];
        foreach ($d['cidr0_cidrs'] ?? [] as $c) {
            $cidrs[] = ($c['v4prefix'] ?? $c['v6prefix'] ?? '?').'/'.($c['length'] ?? '?');
        }

        return [
            'handle' => $d['handle'] ?? null,
            'name' => $d['ldhName'] ?? $d['name'] ?? null,
            'type' => $d['type'] ?? null,
            'country' => $d['country'] ?? null,
            'status' => array_values($d['status'] ?? []),
            'range' => isset($d['startAddress']) ? $d['startAddress'].' – '.($d['endAddress'] ?? '?') : (isset($d['startAutnum']) ? 'AS'.$d['startAutnum'].(($d['endAutnum'] ?? $d['startAutnum']) !== $d['startAutnum'] ? '–AS'.$d['endAutnum'] : '') : null),
            'cidrs' => $cidrs,
            'parent' => $d['parentHandle'] ?? null,
            'nameservers' => array_values(array_filter(array_map(static fn (array $n): ?string => isset($n['ldhName']) ? strtolower((string) $n['ldhName']) : null, $d['nameservers'] ?? []))),
            'dnssec' => isset($d['secureDNS']['delegationSigned']) ? (bool) $d['secureDNS']['delegationSigned'] : null,
            'registered' => $events['registration'] ?? null,
            'changed' => $events['last changed'] ?? null,
            'expires' => $events['expiration'] ?? null,
            'entities' => self::entities($d['entities'] ?? [], 0),
        ];
    }

    /**
     * @param list<array<string, mixed>> $list
     *
     * @return list<array{roles: list<string>, handle: string|null, name: string|null, org: string|null, email: string|null, country: string|null}>
     */
    private static function entities(array $list, int $depth): array
    {
        $out = [];
        foreach ($list as $e) {
            $card = ['fn' => null, 'org' => null, 'email' => null, 'country' => null];
            foreach ($e['vcardArray'][1] ?? [] as $prop) {
                [$k, , , $v] = $prop + [null, null, null, null];
                match ($k) {
                    'fn' => $card['fn'] ??= \is_string($v) ? $v : null,
                    'org' => $card['org'] ??= \is_string($v) ? $v : (\is_array($v) ? implode(' ', $v) : null),
                    'email' => $card['email'] ??= \is_string($v) ? $v : null,
                    'adr' => $card['country'] ??= \is_array($v) ? (\is_string(end($v)) && '' !== end($v) ? end($v) : ($prop[1]['cc'] ?? null)) : null,
                    default => null,
                };
            }
            $out[] = ['roles' => array_values($e['roles'] ?? []), 'handle' => $e['handle'] ?? null, 'name' => $card['fn'], 'org' => $card['org'], 'email' => $card['email'], 'country' => $card['country']];
            if ($depth < 2) {
                array_push($out, ...self::entities($e['entities'] ?? [], $depth + 1));
            }
        }

        return \array_slice($out, 0, 20);
    }

    /**
     * Vários MACs (um por linha, qualquer formato) → fabricante, flags e onde aparecem.
     *
     * @return list<array<string, mixed>>
     */
    public function macs(string $input): array
    {
        $tokens = array_values(array_filter(preg_split('/[\s,;]+/', trim($input)) ?: []));
        if ([] === $tokens) {
            throw new ValidationException(['macs' => 'Informe ao menos um MAC.']);
        }
        if (\count($tokens) > 200) {
            throw new ValidationException(['macs' => 'Máximo de 200 MACs por consulta.']);
        }
        $items = [];
        $valid = [];
        foreach ($tokens as $t) {
            $hex = MacVendors::hex($t);
            if (null === $hex) {
                // prefixo OUI sozinho (AA:BB:CC / AABBCC)
                $p = strtoupper((string) preg_replace('/[\s:.\-]/', '', $t));
                $items[] = 1 === preg_match('/^[0-9A-F]{6}$/', $p)
                    ? ['input' => $t, 'mac' => null, 'oui' => implode(':', str_split($p, 2)), 'vendor' => $this->vendors->vendor($p.'000'), 'valid' => true, 'flags' => [], 'assets' => [], 'scans' => []]
                    : ['input' => $t, 'mac' => null, 'oui' => null, 'vendor' => null, 'valid' => false, 'flags' => [], 'assets' => [], 'scans' => []];
                continue;
            }
            $mac = (string) MacVendors::normalize($hex);
            $flags = [];
            if ('FFFFFFFFFFFF' === $hex) {
                $flags[] = 'broadcast';
            } elseif (MacVendors::isMulticast($hex)) {
                $flags[] = 'multicast';
            }
            if (MacVendors::isLocal($hex)) {
                $flags[] = 'localmente administrado (aleatório/virtual)';
            }
            if (isset(MacVendors::SHARED_VIRTUAL[$mac])) {
                $flags[] = MacVendors::SHARED_VIRTUAL[$mac].' — repete em várias máquinas';
            }
            if (isset(MacVendors::HYPERVISOR[substr($mac, 0, 8)])) {
                $flags[] = 'máquina virtual ('.MacVendors::HYPERVISOR[substr($mac, 0, 8)].')';
            }
            $valid[$mac] = \count($items);
            $items[] = ['input' => $t, 'mac' => $mac, 'oui' => strtoupper(substr($mac, 0, 8)), 'vendor' => $this->vendors->vendor($hex), 'valid' => true, 'flags' => $flags, 'assets' => [], 'scans' => []];
        }
        if ([] !== $valid) {
            $macs = array_keys($valid);
            foreach ($this->db->fetchAllAssociative(<<<'SQL'
                SELECT x.mac, a.uuid, a.name, t.code AS type, a.inventory_number, x.port, a.last_inventory_at
                FROM (
                    SELECT p.mac_address AS mac, p.asset_id, p.name AS port FROM network_port p WHERE p.mac_address IN (:macs)
                    UNION
                    SELECT lower(a.attributes->>'mac'), a.id, NULL FROM asset a WHERE lower(a.attributes->>'mac') IN (:macs)
                ) x JOIN asset a ON a.id = x.asset_id AND a.deleted_at IS NULL
                LEFT JOIN asset_type t ON t.id = a.asset_type_id
                ORDER BY a.last_inventory_at DESC NULLS LAST
                SQL, ['macs' => $macs], ['macs' => ArrayParameterType::STRING]) as $r) {
                $items[$valid[$r['mac']]]['assets'][] = ['uuid' => $r['uuid'], 'name' => $r['name'], 'type' => $r['type'], 'inventory_number' => $r['inventory_number'], 'port' => $r['port'], 'last_inventory_at' => $r['last_inventory_at']];
            }
            foreach ($this->db->fetchAllAssociative(<<<'SQL'
                SELECT * FROM (
                    SELECT h.mac, h.ip, h.scan_id, s.target, s.created_at, row_number() OVER (PARTITION BY h.mac ORDER BY h.scan_id DESC) AS rn
                    FROM network_scan_host h JOIN network_scan s ON s.id = h.scan_id WHERE h.mac IN (:macs)
                ) z WHERE rn <= 5
                SQL, ['macs' => $macs], ['macs' => ArrayParameterType::STRING]) as $r) {
                $items[$valid[$r['mac']]]['scans'][] = ['scan_id' => (int) $r['scan_id'], 'ip' => $r['ip'], 'target' => $r['target'], 'at' => $r['created_at']];
            }
        }

        return $items;
    }

    private function txt(string $name): ?string
    {
        if (!Command::exists('dig')) {
            return null;
        }
        $r = Command::run(['dig', '+short', '+time=3', '+tries=2', $name, 'TXT'], 10);
        $line = trim(strtok($r['stdout'], "\n") ?: '');

        if ('' === $line || str_starts_with($line, ';')) {
            return null;
        }
        // TXT com várias strings ("a" "b") → concatena, como o RFC 7208 faz
        return preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $line, $m) > 0 ? implode('', $m[1]) : $line;
    }
}
