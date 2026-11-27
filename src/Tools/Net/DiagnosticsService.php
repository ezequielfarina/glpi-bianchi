<?php

declare(strict_types=1);

namespace App\Tools\Net;

use App\Ui\Write\ValidationException;

/**
 * Ferramentas → Diagnóstico (ADR-0013): ping, traceroute (mtr, com fallback para traceroute)
 * e teste de portas TCP com certificado TLS e banner. Executado a partir do CT, de forma síncrona
 * e com limites curtos (cabe numa requisição).
 *
 * Requisitos no CT (install.sh): iputils-ping + net.ipv4.ping_group_range, mtr-tiny (mtr-packet com
 * cap_net_raw), traceroute.
 */
final class DiagnosticsService
{
    /** Portas em que vale tentar o handshake TLS automaticamente. */
    private const array TLS_PORTS = [443, 465, 636, 853, 990, 993, 995, 3269, 5986, 8006, 8443, 9443, 10443];

    /** Nomes amigáveis das portas mais comuns em infraestrutura. */
    public const array PORT_NAMES = [
        21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP', 88 => 'Kerberos', 110 => 'POP3',
        135 => 'RPC', 139 => 'NetBIOS', 143 => 'IMAP', 389 => 'LDAP', 443 => 'HTTPS', 445 => 'SMB', 465 => 'SMTPS', 541 => 'FortiManager',
        587 => 'Submission', 636 => 'LDAPS', 853 => 'DoT', 993 => 'IMAPS', 995 => 'POP3S', 1433 => 'SQL Server', 1521 => 'Oracle',
        1812 => 'RADIUS', 2049 => 'NFS', 3268 => 'Global Catalog', 3269 => 'Global Catalog SSL', 3306 => 'MySQL', 3389 => 'RDP',
        5060 => 'SIP', 5432 => 'PostgreSQL', 5985 => 'WinRM', 5986 => 'WinRM HTTPS', 6379 => 'Redis/Valkey', 8006 => 'Proxmox',
        8080 => 'HTTP alt', 8291 => 'Winbox', 8443 => 'HTTPS alt', 8728 => 'MikroTik API', 8729 => 'MikroTik API-SSL', 9100 => 'JetDirect',
        10050 => 'Zabbix agent', 10051 => 'Zabbix server', 10443 => 'FortiGate SSL-VPN', 62354 => 'GLPI Agent',
    ];

    /**
     * @return array<string, mixed>
     */
    public function ping(string $hostInput, int $count = 4): array
    {
        $host = $this->host($hostInput);
        $count = max(1, min(20, $count));
        $r = Command::run(['ping', '-n', '-O', '-c', (string) $count, '-i', '0.5', '-W', '2', $host], 5 + $count * 2.5);
        $out = $r['stdout'];
        $replies = [];
        foreach (explode("\n", $out) as $line) {
            if (1 === preg_match('/icmp_seq=(\d+)(?: ttl=(\d+))? time=([\d.]+) ms/', $line, $m)) {
                $replies[] = ['seq' => (int) $m[1], 'ttl' => '' === $m[2] ? null : (int) $m[2], 'ms' => (float) $m[3], 'ok' => true, 'text' => null];
            } elseif (1 === preg_match('/no answer yet for icmp_seq=(\d+)/', $line, $m)) {
                $replies[] = ['seq' => (int) $m[1], 'ttl' => null, 'ms' => null, 'ok' => false, 'text' => 'sem resposta'];
            } elseif (1 === preg_match('/^From (\S+).*icmp_seq=(\d+) (.+)$/', $line, $m)) {
                $replies[] = ['seq' => (int) $m[2], 'ttl' => null, 'ms' => null, 'ok' => false, 'text' => $m[3].' (de '.$m[1].')'];
            }
        }
        preg_match('/^PING \S+ \(([^)]+)\)/m', $out, $ip);
        preg_match('/(\d+) packets transmitted, (\d+) received.*?([\d.]+)% packet loss/', $out, $sum);
        preg_match('#= ([\d.]+)/([\d.]+)/([\d.]+)/([\d.]+) ms#', $out, $rtt);
        if ([] === $sum) {
            throw new ValidationException(['host' => $this->explain($r['stderr'] ?: $out, 'ping')]);
        }

        return [
            'host' => $host,
            'ip' => $ip[1] ?? $host,
            'sent' => (int) $sum[1],
            'received' => (int) $sum[2],
            'loss' => (float) $sum[3],
            'rtt' => [] === $rtt ? null : ['min' => (float) $rtt[1], 'avg' => (float) $rtt[2], 'max' => (float) $rtt[3], 'mdev' => (float) $rtt[4]],
            'replies' => $replies,
            'raw' => $out,
        ];
    }

    /**
     * Traceroute com estatística por salto (mtr --report). Sem mtr, cai para traceroute UDP.
     *
     * @return array<string, mixed>
     */
    public function trace(string $hostInput, int $cycles = 3): array
    {
        $host = $this->host($hostInput);
        $cycles = max(1, min(10, $cycles));
        if (Command::exists('mtr')) {
            $r = Command::run(['mtr', '--json', '-b', '-c', (string) $cycles, '-m', '30', $host], 20 + $cycles * 6);
            $data = json_decode($r['stdout'], true);
            if (\is_array($data) && isset($data['report']['hubs'])) {
                $hops = [];
                foreach ($data['report']['hubs'] as $h) {
                    [$name, $ip] = $this->splitHost((string) $h['host']);
                    $hops[] = [
                        'hop' => (int) $h['count'], 'name' => $name, 'ip' => $ip,
                        'loss' => (float) $h['Loss%'], 'sent' => (int) $h['Snt'], 'last' => (float) $h['Last'],
                        'avg' => (float) $h['Avg'], 'best' => (float) $h['Best'], 'worst' => (float) $h['Wrst'], 'stdev' => (float) $h['StDev'],
                    ];
                }

                return ['host' => $host, 'tool' => 'mtr', 'cycles' => $cycles, 'hops' => $hops];
            }
            if (!Command::exists('traceroute')) {
                throw new ValidationException(['host' => $this->explain($r['stderr'], 'mtr')]);
            }
        }
        $r = Command::run(['traceroute', '-q', '1', '-w', '2', '-m', '30', $host], 75);
        $hops = [];
        foreach (explode("\n", $r['stdout']) as $line) {
            if (1 === preg_match('/^\s*(\d+)\s+(.+)$/', $line, $m)) {
                if (1 === preg_match('/^(\S+) \(([^)]+)\)\s+([\d.]+) ms/', trim($m[2]), $x)) {
                    $hops[] = ['hop' => (int) $m[1], 'name' => $x[1] === $x[2] ? null : $x[1], 'ip' => $x[2], 'loss' => 0.0, 'sent' => 1, 'last' => (float) $x[3], 'avg' => (float) $x[3], 'best' => (float) $x[3], 'worst' => (float) $x[3], 'stdev' => 0.0];
                } else {
                    $hops[] = ['hop' => (int) $m[1], 'name' => null, 'ip' => null, 'loss' => 100.0, 'sent' => 1, 'last' => null, 'avg' => null, 'best' => null, 'worst' => null, 'stdev' => null];
                }
            }
        }
        if ([] === $hops) {
            throw new ValidationException(['host' => $this->explain($r['stderr'], 'traceroute')]);
        }

        return ['host' => $host, 'tool' => 'traceroute', 'cycles' => 1, 'hops' => $hops];
    }

    /**
     * Teste de portas TCP: aberta / fechada (RST) / filtrada (sem resposta), tempo de conexão,
     * certificado TLS (sujeito, emissor, validade, SANs, cadeia confiável) e banner do serviço.
     *
     * @return array<string, mixed>
     */
    public function ports(string $hostInput, string $portsInput, bool $tls = false): array
    {
        $host = $this->host($hostInput);
        $ports = self::parsePorts($portsInput);
        $ip = Host::isIp($host) ? $host : (gethostbyname($host) !== $host ? gethostbyname($host) : null);
        if (null === $ip && !Host::isIp($host) && [] === (dns_get_record($host, \DNS_AAAA) ?: [])) {
            throw new ValidationException(['host' => 'Nome não resolve no DNS do servidor.']);
        }
        $addr = str_contains((string) ($ip ?? $host), ':') ? '['.($ip ?? $host).']' : ($ip ?? $host);
        $results = [];
        $deadline = microtime(true) + 40;
        foreach ($ports as $port) {
            if (microtime(true) > $deadline) {
                $results[] = ['port' => $port, 'name' => self::PORT_NAMES[$port] ?? null, 'state' => 'skipped', 'ms' => null, 'error' => 'Tempo total do teste esgotado.', 'tls' => null, 'banner' => null];
                continue;
            }
            $t = microtime(true);
            $errno = 0;
            $errstr = '';
            $s = @stream_socket_client("tcp://{$addr}:{$port}", $errno, $errstr, 3.0);
            $ms = round((microtime(true) - $t) * 1000, 1);
            if (false === $s) {
                $refused = 111 === $errno || str_contains(strtolower($errstr), 'refused');
                $results[] = ['port' => $port, 'name' => self::PORT_NAMES[$port] ?? null, 'state' => $refused ? 'closed' : 'filtered', 'ms' => $refused ? $ms : null,
                    'error' => $refused ? 'conexão recusada (RST)' : ('' !== $errstr ? $errstr : 'sem resposta em 3 s'), 'tls' => null, 'banner' => null];
                continue;
            }
            $banner = null;
            if (!$tls && !\in_array($port, self::TLS_PORTS, true) && !\in_array($port, [80, 8080, 3389, 445, 139, 135], true)) {
                stream_set_timeout($s, 1);
                $b = (string) @fread($s, 256);
                $banner = '' === trim($b) ? null : mb_substr(trim((string) preg_replace('/[^\P{C}\n]/u', '', mb_convert_encoding($b, 'UTF-8', 'UTF-8'))), 0, 200);
            }
            fclose($s);
            $results[] = ['port' => $port, 'name' => self::PORT_NAMES[$port] ?? null, 'state' => 'open', 'ms' => $ms, 'error' => null,
                'tls' => ($tls || \in_array($port, self::TLS_PORTS, true)) ? $this->tlsInfo($addr, $port, $host) : null, 'banner' => $banner];
        }

        return ['host' => $host, 'ip' => $ip, 'results' => $results];
    }

    /** @return list<int> "22,80,443" / "8000-8010" / "22 3389" — até 32 portas */
    public static function parsePorts(string $input): array
    {
        $ports = [];
        foreach (preg_split('/[\s,;]+/', trim($input)) ?: [] as $tok) {
            if ('' === $tok) {
                continue;
            }
            if (1 === preg_match('/^(\d{1,5})-(\d{1,5})$/', $tok, $m) && (int) $m[1] <= (int) $m[2]) {
                for ($p = (int) $m[1]; $p <= (int) $m[2] && \count($ports) <= 32; ++$p) {
                    $ports[] = $p;
                }
            } elseif (ctype_digit($tok)) {
                $ports[] = (int) $tok;
            } else {
                throw new ValidationException(['ports' => "Porta inválida: {$tok}"]);
            }
        }
        $ports = array_values(array_unique($ports));
        if ([] === $ports) {
            throw new ValidationException(['ports' => 'Informe ao menos uma porta.']);
        }
        if (\count($ports) > 32) {
            throw new ValidationException(['ports' => 'Máximo de 32 portas por teste (para varredura use o Scanner de rede).']);
        }
        foreach ($ports as $p) {
            if ($p < 1 || $p > 65535) {
                throw new ValidationException(['ports' => "Porta fora da faixa 1-65535: {$p}"]);
            }
        }

        return $ports;
    }

    /** @return array<string, mixed>|null */
    private function tlsInfo(string $addr, int $port, string $sni): ?array
    {
        $name = Host::isIp($sni) ? null : $sni;
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
            'SNI_enabled' => null !== $name, 'peer_name' => $name ?? '',
        ]]);
        $s = @stream_socket_client("ssl://{$addr}:{$port}", $errno, $errstr, 4.0, \STREAM_CLIENT_CONNECT, $ctx);
        if (false === $s) {
            return ['error' => 'sem TLS nesta porta ou handshake recusado'];
        }
        $meta = stream_get_meta_data($s);
        $params = stream_context_get_params($s);
        fclose($s);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $x = $cert ? openssl_x509_parse($cert) : false;
        if (!\is_array($x)) {
            return ['error' => 'certificado ilegível'];
        }
        // cadeia confiável para o CT (CA do sistema) + nome confere?
        $trusted = false;
        $vctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => null !== $name, 'peer_name' => $name ?? '', 'SNI_enabled' => null !== $name]]);
        $v = @stream_socket_client("ssl://{$addr}:{$port}", $errno, $errstr, 4.0, \STREAM_CLIENT_CONNECT, $vctx);
        if (false !== $v) {
            $trusted = true;
            fclose($v);
        }
        $sans = [];
        foreach (explode(',', (string) ($x['extensions']['subjectAltName'] ?? '')) as $san) {
            if ('' !== trim($san)) {
                $sans[] = trim((string) preg_replace('/^(DNS|IP Address):/', '', trim($san)));
            }
        }
        $validTo = (int) $x['validTo_time_t'];

        return [
            'subject' => $x['subject']['CN'] ?? (\is_array($x['subject']) ? implode(', ', array_map(static fn ($v): string => \is_array($v) ? implode(' ', $v) : (string) $v, $x['subject'])) : null),
            'issuer' => trim(($x['issuer']['CN'] ?? '').(isset($x['issuer']['O']) ? ' — '.(\is_array($x['issuer']['O']) ? implode(' ', $x['issuer']['O']) : $x['issuer']['O']) : '')),
            'valid_from' => date(\DATE_ATOM, (int) $x['validFrom_time_t']),
            'valid_to' => date(\DATE_ATOM, $validTo),
            'days_left' => (int) floor(($validTo - time()) / 86400),
            'sans' => \array_slice($sans, 0, 20),
            'self_signed' => ($x['subject'] ?? null) === ($x['issuer'] ?? null),
            'trusted' => $trusted,
            'protocol' => $meta['crypto']['protocol'] ?? null,
            'cipher' => $meta['crypto']['cipher_name'] ?? null,
            'error' => null,
        ];
    }

    private function host(string $input): string
    {
        try {
            return Host::parse($input);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(['host' => $e->getMessage()]);
        }
    }

    /** @return array{0: string|null, 1: string|null} "nome (ip)" → [nome, ip]; "???" → [null, null] */
    private function splitHost(string $h): array
    {
        if ('???' === $h) {
            return [null, null];
        }
        if (1 === preg_match('/^(\S+) \(([^)]+)\)$/', $h, $m)) {
            return [$m[1], $m[2]];
        }

        return Host::isIp($h) ? [null, $h] : [$h, null];
    }

    private function explain(string $error, string $tool): string
    {
        $e = strtolower($error);

        return match (true) {
            str_contains($e, 'unknown host'), str_contains($e, 'name or service not known'), str_contains($e, 'failed to resolve') => 'Nome não resolve no DNS do servidor.',
            str_contains($e, 'operation not permitted'), str_contains($e, 'permission denied') => "Sem permissão para {$tool} no servidor (ver docs/tools/scanner-de-rede.md → Diagnóstico).",
            str_contains($e, 'network is unreachable') => 'Rede inalcançável a partir do servidor (sem rota).',
            '' === trim($error) => "Falha ao executar {$tool}.",
            default => mb_substr(trim($error), 0, 300),
        };
    }
}
