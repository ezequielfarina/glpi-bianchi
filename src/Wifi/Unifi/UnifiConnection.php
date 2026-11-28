<?php

declare(strict_types=1);

namespace App\Wifi\Unifi;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sessão com um controller UniFi Network pela API clássica (a mesma do painel web do UniFi) — ADR-0020.
 *
 * Modos: `local` (admin local, cookie + CSRF; detecta UniFi OS × controller clássico), `api_key`
 * (UniFi OS / UniFi OS Server, cabeçalho X-API-KEY) e `cloud` (proxy do Site Manager em api.ui.com).
 * Só lê sites, redes Wi-Fi e clientes e grava o filtro de MAC (PUT parcial) e o apelido do cliente.
 *
 * Referências: art-of-wifi/unifi-api-client (Client::set_wlan_mac_filter, create_user, set_sta_name/note),
 * ubntwiki "UniFi Controller API".
 */
final class UnifiConnection
{
    public const string UNIFI_OS = 'unifi_os';
    public const string CLASSIC = 'classic';
    private const string CLOUD_BASE = 'https://api.ui.com/v1/connector/consoles/';
    /** maior lista real: ~300 MACs/rede, 70 redes; 16 MiB sobra muito e barra resposta maliciosa/gigante */
    private const int MAX_RESPONSE = 16 * 1024 * 1024;

    private ?string $platform = null;
    /** @var array<string, string> */
    private array $cookies = [];
    private ?string $csrf = null;
    private bool $loggedIn = false;

    /**
     * @param array{base_url: string, auth_mode: string, username: ?string, secret: ?string, cloud_host_id: ?string, verify_tls: bool, tls_pin: ?string} $cfg
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly array $cfg,
    ) {
        if ('cloud' !== $cfg['auth_mode'] && !$cfg['verify_tls'] && null === $cfg['tls_pin']) {
            throw new UnifiException('Defina como confiar no certificado do controller: CA válida ou certificado fixado (botão "Ler certificado").');
        }
    }

    public function platform(): string
    {
        if (null !== $this->platform) {
            return $this->platform;
        }
        if ('local' !== $this->cfg['auth_mode']) {
            return $this->platform = self::UNIFI_OS;
        }
        // UniFi OS responde 200 na raiz; o controller clássico redireciona para /manage
        $r = $this->send('GET', $this->root().'/', [], false);

        return $this->platform = 200 === $r->getStatusCode() ? self::UNIFI_OS : self::CLASSIC;
    }

    /** @return list<array{ref: string, id: string, description: string}> */
    public function sites(): array
    {
        $out = [];
        foreach ($this->api('GET', '/api/self/sites') as $s) {
            if (\is_array($s) && isset($s['name'])) {
                $out[] = ['ref' => (string) $s['name'], 'id' => (string) ($s['_id'] ?? ''), 'description' => (string) ($s['desc'] ?? $s['name'])];
            }
        }

        return $out;
    }

    /** @return list<array{id: string, name: string, enabled: bool, security: string, filter_enabled: bool, filter_policy: ?string, macs: list<string>}> */
    public function wlans(string $site): array
    {
        return array_values(array_map(self::wlanView(...), array_filter($this->api('GET', '/api/s/'.rawurlencode($site).'/rest/wlanconf'), 'is_array')));
    }

    /** @return array{id: string, name: string, enabled: bool, security: string, filter_enabled: bool, filter_policy: ?string, macs: list<string>} */
    public function wlan(string $site, string $id): array
    {
        $data = $this->api('GET', '/api/s/'.rawurlencode($site).'/rest/wlanconf/'.rawurlencode($id));
        if (!isset($data[0]) || !\is_array($data[0])) {
            throw new UnifiException('Rede Wi-Fi não encontrada no controller (apagada no UniFi?). Sincronize de novo.');
        }

        return self::wlanView($data[0]);
    }

    /**
     * Grava só o filtro de MAC (PUT parcial): senha, rádios e demais campos do Wi-Fi não são enviados.
     *
     * @param list<string> $macs
     */
    public function setMacFilter(string $site, string $id, array $macs, bool $enabled, string $policy): void
    {
        if (!\in_array($policy, ['allow', 'deny'], true)) {
            throw new UnifiException('Política de filtro inválida.');
        }
        $this->api('PUT', '/api/s/'.rawurlencode($site).'/rest/wlanconf/'.rawurlencode($id), [
            'mac_filter_enabled' => $enabled, 'mac_filter_policy' => $policy, 'mac_filter_list' => array_values($macs),
        ]);
    }

    /** @return array<string, array{id: string, name: ?string, hostname: ?string, oui: ?string, note: ?string, last_seen: ?int}> clientes conhecidos por MAC */
    public function knownClients(string $site): array
    {
        $out = [];
        foreach ($this->api('GET', '/api/s/'.rawurlencode($site).'/rest/user') as $u) {
            if (!\is_array($u) || !isset($u['mac'])) {
                continue;
            }
            $out[strtolower((string) $u['mac'])] = [
                'id' => (string) ($u['_id'] ?? ''), 'name' => self::str($u['name'] ?? null), 'hostname' => self::str($u['hostname'] ?? null),
                'oui' => self::str($u['oui'] ?? null), 'note' => self::str($u['note'] ?? null), 'last_seen' => \is_int($u['last_seen'] ?? null) ? $u['last_seen'] : null,
            ];
        }

        return $out;
    }

    /** Nome (alias) e nota do cliente no UniFi: cria o cliente conhecido se ainda não existir. */
    public function setClientAlias(string $site, string $mac, string $name, string $note, ?array $known = null): void
    {
        $known ??= $this->knownClients($site);
        $s = rawurlencode($site);
        if (isset($known[$mac]) && '' !== $known[$mac]['id']) {
            $this->api('POST', "/api/s/$s/upd/user/".rawurlencode($known[$mac]['id']), ['name' => $name, 'note' => $note]);

            return;
        }
        $group = null;
        foreach ($this->api('GET', "/api/s/$s/list/usergroup") as $g) {
            if (\is_array($g) && isset($g['_id'])) {
                $group ??= (string) $g['_id'];
                if (($g['attr_no_delete'] ?? false) === true) {
                    $group = (string) $g['_id'];
                    break;
                }
            }
        }
        if (null === $group) {
            return;
        }
        $this->api('POST', "/api/s/$s/group/user", ['objects' => [['data' => ['mac' => $mac, 'usergroup_id' => $group, 'name' => $name, 'note' => $note]]]]);
    }

    public function version(string $site): ?string
    {
        try {
            $info = $this->api('GET', '/api/s/'.rawurlencode($site).'/stat/sysinfo');
        } catch (UnifiException) {
            return null;
        }

        return isset($info[0]) && \is_array($info[0]) ? self::str($info[0]['version'] ?? null) : null;
    }

    public function logout(): void
    {
        if (!$this->loggedIn) {
            return;
        }
        try {
            $path = self::UNIFI_OS === $this->platform ? '/api/auth/logout' : '/api/logout';
            $this->send('POST', $this->root().$path, ['json' => new \stdClass()]);
        } catch (\Throwable) {
            // melhor esforço: a sessão expira sozinha no controller
        }
        $this->loggedIn = false;
        $this->cookies = [];
    }

    /**
     * Chamada à API clássica com o envelope {meta: {rc}, data: [...]}.
     *
     * @param array<string, mixed>|null $body
     *
     * @return list<mixed>
     */
    private function api(string $method, string $path, ?array $body = null, bool $retry = true): array
    {
        if ('local' === $this->cfg['auth_mode'] && !$this->loggedIn) {
            $this->login();
        }
        $opts = null === $body ? [] : ['json' => $body];
        $r = $this->send($method, $this->apiBase().$path, $opts);
        $code = $r->getStatusCode();
        if (401 === $code && $retry && 'local' === $this->cfg['auth_mode']) {
            $this->loggedIn = false; // sessão expirou: entra de novo uma vez

            return $this->api($method, $path, $body, false);
        }
        try {
            $raw = $r->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw $e->getPrevious() instanceof UnifiException ? $e->getPrevious() : new UnifiException(self::transportMessage($e->getMessage(), $this->cfg['base_url']), 0, $e);
        }
        $json = json_decode($raw, true);
        $msg = \is_array($json) ? (string) ($json['meta']['msg'] ?? '') : '';
        if (401 === $code || 'api.err.LoginRequired' === $msg) {
            throw new UnifiException('O controller recusou a credencial (usuário/senha ou API key). Confira o cadastro do controller.');
        }
        if (403 === $code || 'api.err.NoPermission' === $msg) {
            throw new UnifiException('A conta da integração não tem permissão para isso no UniFi (precisa ser administrador do site).');
        }
        if (404 === $code) {
            throw new UnifiException('Recurso não encontrado no controller ('.$path.'). Versão do UniFi Network incompatível ou site/rede apagado.');
        }
        if ($code >= 400 || !\is_array($json) || 'ok' !== ($json['meta']['rc'] ?? null)) {
            throw new UnifiException(\sprintf('O controller respondeu erro (HTTP %d%s).', $code, '' !== $msg ? ': '.$msg : ''));
        }

        return array_values(\is_array($json['data'] ?? null) ? $json['data'] : []);
    }

    private function login(): void
    {
        $user = (string) $this->cfg['username'];
        $pass = (string) $this->cfg['secret'];
        if ('' === $user || '' === $pass) {
            throw new UnifiException('Informe usuário e senha do admin local do UniFi no cadastro do controller.');
        }
        $os = self::UNIFI_OS === $this->platform();
        $r = $this->send('POST', $this->root().($os ? '/api/auth/login' : '/api/login'), ['json' => ['username' => $user, 'password' => $pass, 'remember' => false]], false);
        $code = $r->getStatusCode();
        if (200 !== $code) {
            throw new UnifiException(self::loginMessage($code, $r->getContent(false)));
        }
        $this->loggedIn = true;
    }

    /** @param array<string, mixed> $opts */
    private function send(string $method, string $url, array $opts = [], bool $auth = true): ResponseInterface
    {
        $headers = ['Accept' => 'application/json'];
        if ($auth && 'local' !== $this->cfg['auth_mode']) {
            $headers['X-API-KEY'] = (string) $this->cfg['secret'];
        }
        if ([] !== $this->cookies) {
            $headers['Cookie'] = implode('; ', array_map(static fn (string $k, string $v): string => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        if ('GET' !== $method && null !== $this->csrf) {
            $headers['X-Csrf-Token'] = $this->csrf;
        }
        $opts += ['timeout' => 10, 'max_duration' => 25, 'max_redirects' => 0, 'headers' => $headers,
            'on_progress' => static function (int $dlNow): void {
                if ($dlNow > self::MAX_RESPONSE) {
                    throw new UnifiException('Resposta do controller grande demais (limite 16 MiB) — abortado.');
                }
            }] + $this->tlsOptions();
        // GET e PUT (lista completa) são idempotentes: uma nova tentativa em falha passageira de rede/proxy
        $attempts = \in_array($method, ['GET', 'PUT'], true) || str_ends_with($url, '/login') ? 2 : 1;
        for ($i = 1;; ++$i) {
            try {
                $r = $this->http->request($method, $url, $opts);
                $code = $r->getStatusCode(); // força a conexão aqui para traduzir erro de transporte
                if ($i < $attempts && \in_array($code, [502, 503, 504], true)) {
                    usleep(700_000);
                    continue;
                }
                $this->capture($r);

                return $r;
            } catch (TransportExceptionInterface|TransportException $e) {
                if ($e->getPrevious() instanceof UnifiException) {
                    throw $e->getPrevious();
                }
                if ($i < $attempts && !self::isTlsError($e->getMessage())) {
                    usleep(700_000);
                    continue;
                }
                throw new UnifiException(self::transportMessage($e->getMessage(), $this->cfg['base_url']), 0, $e);
            }
        }
    }

    private static function isTlsError(string $error): bool
    {
        return str_contains($error, 'SSL') || str_contains($error, 'certificate') || str_contains($error, 'pinned') || str_contains($error, 'PINNEDPUBLICKEY');
    }

    /** @return array<string, mixed> */
    private function tlsOptions(): array
    {
        if ('cloud' === $this->cfg['auth_mode'] || $this->cfg['verify_tls']) {
            return ['verify_peer' => true, 'verify_host' => true];
        }

        // certificado autoassinado: não valida a cadeia, mas exige a MESMA chave pública fixada (curl confere o pin mesmo assim)
        return ['verify_peer' => false, 'verify_host' => false, 'peer_fingerprint' => ['pin-sha256' => [(string) $this->cfg['tls_pin']]]];
    }

    private function capture(ResponseInterface $r): void
    {
        $h = $r->getHeaders(false);
        foreach ($h['set-cookie'] ?? [] as $line) {
            if (1 === preg_match('/^\s*([^=;\s]+)=([^;]*)/', $line, $m)) {
                $this->cookies[$m[1]] = $m[2];
            }
        }
        $token = $h['x-updated-csrf-token'][0] ?? $h['x-csrf-token'][0] ?? null;
        if (null === $token && isset($this->cookies['csrf_token'])) {
            $token = $this->cookies['csrf_token'];
        }
        if (null === $token && isset($this->cookies['TOKEN'])) {
            // UniFi OS: o csrfToken vem dentro do JWT do cookie TOKEN
            $parts = explode('.', $this->cookies['TOKEN']);
            $payload = isset($parts[1]) ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true) : null;
            $token = \is_array($payload) && \is_string($payload['csrfToken'] ?? null) ? $payload['csrfToken'] : null;
        }
        if (null !== $token && '' !== $token) {
            $this->csrf = $token;
        }
    }

    private function root(): string
    {
        return 'cloud' === $this->cfg['auth_mode']
            ? self::CLOUD_BASE.rawurlencode((string) $this->cfg['cloud_host_id'])
            : rtrim($this->cfg['base_url'], '/');
    }

    private function apiBase(): string
    {
        return match (true) {
            'cloud' === $this->cfg['auth_mode'] => $this->root().'/network',
            self::UNIFI_OS === $this->platform() => $this->root().'/proxy/network',
            default => $this->root(),
        };
    }

    /**
     * Só o que interessa do Wi-Fi — o GET devolve também `x_passphrase` (senha), que nunca sai daqui.
     *
     * @param array<mixed> $w
     *
     * @return array{id: string, name: string, enabled: bool, security: string, filter_enabled: bool, filter_policy: ?string, macs: list<string>}
     */
    private static function wlanView(array $w): array
    {
        $policy = \is_string($w['mac_filter_policy'] ?? null) && \in_array($w['mac_filter_policy'], ['allow', 'deny'], true) ? $w['mac_filter_policy'] : null;
        $macs = [];
        foreach (\is_array($w['mac_filter_list'] ?? null) ? $w['mac_filter_list'] : [] as $m) {
            if (\is_string($m) && null !== ($n = MacAddress::normalize($m))) {
                $macs[] = $n;
            }
        }
        $security = (string) ($w['security'] ?? '');
        if (\is_string($w['wpa_mode'] ?? null) && 'open' !== $security) {
            $security .= '/'.$w['wpa_mode'];
        }

        return [
            'id' => (string) ($w['_id'] ?? ''), 'name' => (string) ($w['name'] ?? '?'), 'enabled' => (bool) ($w['enabled'] ?? true), 'security' => $security,
            'filter_enabled' => (bool) ($w['mac_filter_enabled'] ?? false), 'filter_policy' => $policy, 'macs' => array_values(array_unique($macs)),
        ];
    }

    /** Motivo do login recusado a partir do `meta.msg` do UniFi (api.err.*). */
    private static function loginMessage(int $code, string $body): string
    {
        $json = json_decode($body, true);
        $msg = \is_array($json) ? (string) ($json['meta']['msg'] ?? '') : '';

        return match (true) {
            str_contains($msg, '2fa') || str_contains($msg, 'Ubic2fa') || str_contains($msg, 'MFA') => 'Login no UniFi recusado: a conta exige 2FA. Use um admin LOCAL ("Remote Access" desmarcado), sem 2FA.',
            'api.err.Invalid' === $msg || 'api.err.InvalidCredentials' === $msg || (400 === $code && '' === $msg) => 'Login no UniFi recusado: usuário ou senha incorretos. Confira em "Trocar senha" (o navegador pode ter preenchido a senha sozinho).',
            str_contains($msg, 'LoginBlocked') || str_contains($msg, 'Throttle') || 429 === $code || 499 === $code => 'Login no UniFi bloqueado por excesso de tentativas. Aguarde alguns minutos e teste de novo.',
            str_contains($msg, 'Ubic') || str_contains($msg, 'Sso') => 'Login no UniFi recusado: conta de nuvem (ui.com). Use um admin LOCAL ("Remote Access" desmarcado).',
            default => \sprintf('Login no UniFi recusado (HTTP %d%s). Use um admin LOCAL, sem 2FA e com "Remote Access" desmarcado.', $code, '' !== $msg ? ': '.$msg : ''),
        };
    }

    private static function str(mixed $v): ?string
    {
        return \is_string($v) && '' !== $v ? $v : null;
    }

    private static function transportMessage(string $error, string $base): string
    {
        $host = (string) parse_url($base, \PHP_URL_HOST).(null !== parse_url($base, \PHP_URL_PORT) ? ':'.parse_url($base, \PHP_URL_PORT) : '');

        return match (true) {
            str_contains($error, 'pinned') || str_contains($error, 'PINNEDPUBLICKEY') || str_contains($error, 'fingerprint') => "O certificado de $host MUDOU em relação ao fixado. Se foi renovado por você, edite o controller, clique em \"Ler certificado\" e fixe o novo; se não, pode ser interceptação — não continue.",
            str_contains($error, 'SSL') || str_contains($error, 'certificate') => "Certificado de $host não é confiável. Edite o controller e use \"Ler certificado\" para fixá-lo, ou instale a CA no CT.",
            str_contains($error, 'timed out') || str_contains($error, 'Timeout') || str_contains($error, 'Idle timeout') => "Sem resposta de $host (tempo esgotado). Verifique IP/porta e se o firewall libera o CT até a VM.",
            str_contains($error, 'Could not resolve') => "Nome não resolvido: $host. Use o IP da VM ou ajuste o DNS do CT.",
            str_contains($error, 'refused') || str_contains($error, 'Connection refused') => "Conexão recusada por $host: porta errada? (8443 controller clássico, 11443 UniFi OS Server, 443 console).",
            default => "Falha de conexão com $host: ".mb_substr($error, 0, 160),
        };
    }
}
