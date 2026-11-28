<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Controller UniFi falso para os testes (ADR-0020): fala a API clássica (`/api/...` no controller clássico,
 * `/proxy/network/api/...` no UniFi OS), exige login (cookie + CSRF) ou X-API-KEY, e devolve `x_passphrase`
 * no GET do Wi-Fi como o real — para provar que a senha da rede nunca sai do cliente.
 * Estado estático: o KernelBrowser recria o container a cada requisição.
 */
final class FakeUnifi implements HttpClientInterface
{
    use DecoratorTrait;

    public const string USER = 'integracao';
    public const string PASS = 'senha-do-unifi';
    public const string KEY = 'api-key-de-teste';
    public const string PASSPHRASE = 'SenhaSecretaDoWifi!';

    /** @var array<string, array{desc: string, wlans: array<string, array<string, mixed>>, users: array<string, array<string, mixed>>}> */
    public static array $sites = [];
    /** @var list<array{method: string, path: string, body: mixed}> */
    public static array $calls = [];
    public static bool $down = false;
    public static bool $ignoreWrites = false;
    public static string $pass = self::PASS;
    /** quantas respostas 503 devolver antes de voltar ao normal (falha passageira) */
    public static int $fail503 = 0;

    public function __construct()
    {
        $this->client = new MockHttpClient($this->handle(...), 'https://unifi.test:8443');
    }

    public static function reset(): void
    {
        self::$down = false;
        self::$ignoreWrites = false;
        self::$pass = self::PASS;
        self::$fail503 = 0;
        self::$calls = [];
        self::$sites = [
            'default' => ['desc' => 'Matriz', 'users' => [], 'wlans' => [
                'w1' => ['_id' => 'w1', 'name' => 'BIANCHI-CORP', 'enabled' => true, 'security' => 'wpapsk', 'wpa_mode' => 'wpa2', 'x_passphrase' => self::PASSPHRASE,
                    'mac_filter_enabled' => true, 'mac_filter_policy' => 'allow', 'mac_filter_list' => ['A4:BB:CC:00:00:01', 'a4:bb:cc:00:00:02']],
                'w2' => ['_id' => 'w2', 'name' => 'BIANCHI-VISITANTE', 'enabled' => true, 'security' => 'wpapsk', 'x_passphrase' => self::PASSPHRASE,
                    'mac_filter_enabled' => false, 'mac_filter_policy' => 'allow', 'mac_filter_list' => []],
            ]],
            'e6ovl2sz' => ['desc' => 'Filial Norte', 'users' => [], 'wlans' => [
                'w3' => ['_id' => 'w3', 'name' => 'NORTE-BLOQUEIO', 'enabled' => true, 'security' => 'wpapsk', 'x_passphrase' => self::PASSPHRASE,
                    'mac_filter_enabled' => true, 'mac_filter_policy' => 'deny', 'mac_filter_list' => ['de:ad:be:ef:00:01']],
            ]],
        ];
    }

    /** @param array<string, mixed> $options */
    private function handle(string $method, string $url, array $options): MockResponse
    {
        if (self::$down) {
            return new MockResponse('', ['error' => 'Connection refused']);
        }
        if (self::$fail503 > 0 && 'GET' === $method) {
            --self::$fail503;

            return new MockResponse('<html>503</html>', ['http_code' => 503]);
        }
        $path = (string) parse_url($url, \PHP_URL_PATH);
        $headers = [];
        foreach ($options['headers'] ?? [] as $h) {
            [$k, $v] = array_map('trim', explode(':', (string) $h, 2)) + [1 => ''];
            $headers[strtolower($k)] = $v;
        }
        $body = isset($options['body']) && \is_string($options['body']) && '' !== $options['body'] ? json_decode($options['body'], true) : null;
        self::$calls[] = ['method' => $method, 'path' => $path, 'body' => $body];

        // detecção de plataforma: controller clássico redireciona a raiz
        if ('/' === $path) {
            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: /manage']]);
        }
        if ('/api/login' === $path) {
            $ok = \is_array($body) && self::USER === ($body['username'] ?? null) && self::$pass === ($body['password'] ?? null);

            return $ok ? self::ok([], ['Set-Cookie: unifises=sess123; Path=/; HttpOnly', 'Set-Cookie: csrf_token=csrf456; Path=/'])
                : new MockResponse('{"meta":{"rc":"error","msg":"api.err.Invalid"},"data":[]}', ['http_code' => 400]);
        }
        if ('/api/logout' === $path) {
            return self::ok([]);
        }
        $os = str_starts_with($path, '/proxy/network');
        if ($os) {
            if (self::KEY !== ($headers['x-api-key'] ?? null)) {
                return new MockResponse('{"error":"unauthorized"}', ['http_code' => 401]);
            }
            $path = substr($path, \strlen('/proxy/network'));
        } else {
            if (!str_contains($headers['cookie'] ?? '', 'unifises=sess123')) {
                return new MockResponse('{"meta":{"rc":"error","msg":"api.err.LoginRequired"},"data":[]}', ['http_code' => 401]);
            }
            if ('GET' !== $method && 'csrf456' !== ($headers['x-csrf-token'] ?? null)) {
                return new MockResponse('{"meta":{"rc":"error","msg":"api.err.InvalidCsrf"},"data":[]}', ['http_code' => 403]);
            }
        }

        if ('/api/self/sites' === $path) {
            $out = [];
            foreach (self::$sites as $ref => $s) {
                $out[] = ['_id' => 'id-'.$ref, 'name' => $ref, 'desc' => $s['desc']];
            }

            return self::ok($out);
        }
        if (1 !== preg_match('#^/api/s/([^/]+)/(.+)$#', $path, $m) || !isset(self::$sites[$m[1]])) {
            return new MockResponse('{"meta":{"rc":"error","msg":"api.err.NotFound"}}', ['http_code' => 404]);
        }
        $site = $m[1];
        $rest = $m[2];
        $s = &self::$sites[$site];

        if ('stat/sysinfo' === $rest) {
            return self::ok([['version' => '9.0.114']]);
        }
        if ('rest/wlanconf' === $rest) {
            return self::ok(array_values($s['wlans']));
        }
        if (1 === preg_match('#^rest/wlanconf/(\w+)$#', $rest, $w)) {
            if (!isset($s['wlans'][$w[1]])) {
                return new MockResponse('{"meta":{"rc":"error","msg":"api.err.IdInvalid"}}', ['http_code' => 400]);
            }
            if ('PUT' === $method && \is_array($body) && !self::$ignoreWrites) {
                foreach ($body as $k => $v) {
                    $s['wlans'][$w[1]][$k] = $v; // PUT parcial, como o real
                }
            }

            return self::ok([$s['wlans'][$w[1]]]);
        }
        if ('rest/user' === $rest) {
            return self::ok(array_values($s['users']));
        }
        if ('list/usergroup' === $rest) {
            return self::ok([['_id' => 'g-default', 'name' => 'Default', 'attr_no_delete' => true]]);
        }
        if ('group/user' === $rest && \is_array($body)) {
            foreach ($body['objects'] ?? [] as $o) {
                $mac = strtolower((string) $o['data']['mac']);
                $s['users'][$mac] = ['_id' => 'u-'.str_replace(':', '', $mac), 'mac' => $mac, 'name' => $o['data']['name'], 'note' => $o['data']['note']];
            }

            return self::ok([]);
        }
        if (1 === preg_match('#^upd/user/([\w-]+)$#', $rest, $u) && \is_array($body)) {
            foreach ($s['users'] as $mac => $usr) {
                if ($usr['_id'] === $u[1]) {
                    $s['users'][$mac] = array_merge($usr, $body);
                }
            }

            return self::ok([]);
        }

        return new MockResponse('{"meta":{"rc":"error","msg":"api.err.NotFound"}}', ['http_code' => 404]);
    }

    /**
     * @param list<mixed>  $data
     * @param list<string> $headers
     */
    private static function ok(array $data, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode(['meta' => ['rc' => 'ok'], 'data' => $data]), ['http_code' => 200, 'response_headers' => array_merge(['Content-Type: application/json'], $headers)]);
    }
}
