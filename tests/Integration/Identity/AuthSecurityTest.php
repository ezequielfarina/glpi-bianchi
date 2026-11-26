<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Security\Totp;
use App\Tests\Integration\Support\LogsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Login, sessão, perfis, CSRF, bloqueio por tentativas, senha temporária e 2FA (ADR-0011).
 */
final class AuthSecurityTest extends WebTestCase
{
    use LogsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testLoginSetsHardenedCookieAndStoresOnlyArgon2Hash(): void
    {
        self::createAccount('ana');
        $this->login($this->client, 'ana');

        $cookie = $this->client->getResponse()->headers->getCookies()[0];
        self::assertSame('bianchi_sid', $cookie->getName());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());
        self::assertSame('/api/ui', $cookie->getPath());

        $db = $this->db();
        self::assertStringStartsWith('$argon2id$', (string) $db->fetchOne("SELECT password_hash FROM app_account WHERE username = 'ana'"));
        // o banco guarda o SHA-256 do token, nunca o token
        self::assertSame(hash('sha256', (string) $cookie->getValue()), $db->fetchOne('SELECT token_hash FROM user_session ORDER BY id DESC LIMIT 1'));

        self::assertSame('ana', $this->get('/api/ui/me')['username']);
        $this->post('/api/ui/auth/logout');
        $this->client->request('GET', '/api/ui/me', server: self::UI);
        self::assertResponseStatusCodeSame(401);
    }

    public function testWrongPasswordAndUnknownUserGetSameAnswer(): void
    {
        self::createAccount('ana');
        $a = $this->login($this->client, 'ana', 'senha errada qualquer', 401);
        $b = $this->login($this->client, 'nao.existe', 'senha errada qualquer', 401);
        self::assertSame($a['error'], $b['error']);
        self::assertSame(2, (int) $this->db()->fetchOne("SELECT count(*) FROM auth_event WHERE event = 'login_failed'"));
    }

    public function testBruteForceIsThrottledEvenWithCorrectPasswordUntilUnlock(): void
    {
        self::createAccount('ana', 'viewer');
        for ($i = 0; $i < 5; ++$i) {
            $this->login($this->client, 'ana', 'tentativa '.$i.' errada', 401);
        }
        $r = $this->login($this->client, 'ana', self::TEST_PASSWORD, 429);
        self::assertSame('throttled', $r['code']);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));

        // administrador desbloqueia
        self::createAccount('chefe');
        $this->login($this->client, 'chefe');
        $id = (int) $this->db()->fetchOne("SELECT id FROM app_account WHERE username = 'ana'");
        self::assertTrue(array_values(array_filter($this->get('/api/ui/users')['items'], static fn (array $u): bool => 'ana' === $u['username']))[0]['locked']);
        $this->post("/api/ui/users/$id/unlock");
        $this->login($this->client, 'ana');
    }

    public function testCsrfHeaderAndOriginAreRequiredForWrites(): void
    {
        $this->loginAs($this->client, 'admin');
        $this->client->request('POST', '/api/ui/branches', server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: '{"name":"X"}');
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('csrf', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/api/ui/branches', server: self::UI + ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://evil.example'], content: '{"name":"X"}');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/api/ui/branches', server: self::UI + ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: '{"name":"X"}');
        self::assertResponseStatusCodeSame(201);
    }

    public function testRolesAreEnforcedServerSide(): void
    {
        $this->loginAs($this->client, 'viewer');
        $this->get('/api/ui/overview');
        $this->post('/api/ui/assets', ['type' => 'printer', 'name' => 'X'], 403);
        $this->client->request('GET', '/api/ui/users', server: self::UI);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/ui/licenses/1/key', server: self::UI);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->client, 'editor');
        $uuid = $this->post('/api/ui/assets', ['type' => 'printer', 'name' => 'IMP-01'], 201)['uuid'];
        // histórico registra quem fez (não mais "painel")
        self::assertSame('editor.teste', $this->db()->fetchOne("SELECT actor FROM asset_history WHERE field = 'asset.created' AND asset_id = (SELECT id FROM asset WHERE uuid = ?)", [$uuid]));
        $this->post('/api/ui/settings', ['inventory.frequency_hours' => 12], 403, 'PUT');
        $this->client->request('GET', '/api/ui/audit', server: self::UI);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTemporaryPasswordForcesChangeAndRevokesOtherSessions(): void
    {
        $this->loginAs($this->client, 'admin');
        $created = $this->post('/api/ui/users', ['username' => 'Maria.Souza', 'display_name' => 'Maria Souza', 'role' => 'editor'], 201);
        self::assertNotNull($created['temporary_password']);

        $admin = $this->sessionCookie();

        self::assertTrue($this->login($this->client, 'maria.souza', $created['temporary_password'])['must_change_password']);
        $maria = $this->sessionCookie();
        $this->client->request('GET', '/api/ui/overview', server: self::UI);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('password_change_required', (string) $this->client->getResponse()->getContent());

        foreach (['curta' => 422, 'maria.souza-2026-ok' => 422, '123456789012' => 422, 'cafe preto na mesa sete' => 200] as $new => $expect) {
            $this->post('/api/ui/me/password', ['current_password' => $created['temporary_password'], 'new_password' => (string) $new], $expect);
        }
        $this->get('/api/ui/overview');

        // reset pelo admin derruba a sessão dela na hora
        $this->useSession($admin);
        $this->post("/api/ui/users/{$created['id']}/reset-password");
        $this->useSession($maria);
        $this->client->request('GET', '/api/ui/overview', server: self::UI);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminGuardrails(): void
    {
        self::createAccount('unico.admin');
        $this->login($this->client, 'unico.admin');
        $me = $this->get('/api/ui/me');
        $this->post("/api/ui/users/{$me['id']}", ['role' => 'viewer'], 422, 'PATCH');
        $this->post("/api/ui/users/{$me['id']}", ['active' => false], 422, 'PATCH');
        $this->post("/api/ui/users/{$me['id']}", null, 422, 'DELETE');
        $this->post('/api/ui/users', ['username' => 'x', 'display_name' => 'X'], 422); // login curto
    }

    public function testTotpTwoStepLoginWithReplayProtection(): void
    {
        self::createAccount('ana', 'editor');
        $this->login($this->client, 'ana');
        $setup = $this->post('/api/ui/me/mfa/setup');
        self::assertStringStartsWith('otpauth://totp/', $setup['uri']);
        $this->post('/api/ui/me/mfa/enable', ['code' => '000000'], 422);
        $this->post('/api/ui/me/mfa/enable', ['code' => Totp::at($setup['secret'], time())]);
        self::assertStringNotContainsString($setup['secret'], (string) $this->db()->fetchOne("SELECT totp_secret FROM app_account WHERE username = 'ana'"), 'segredo cifrado no banco');
        $this->post('/api/ui/auth/logout');

        // senha certa → só etapa 2; a sessão pendente não acessa nada
        self::assertTrue($this->login($this->client, 'ana')['mfa_required']);
        $this->client->request('GET', '/api/ui/me', server: self::UI);
        self::assertResponseStatusCodeSame(401);
        $this->post('/api/ui/auth/mfa', ['code' => '123456'], 401);

        $next = Totp::at($setup['secret'], time() + Totp::PERIOD); // passo seguinte (o atual já foi usado na ativação)
        $this->post('/api/ui/auth/mfa', ['code' => $next]);
        self::assertSame('ana', $this->get('/api/ui/me')['username']);
        $this->post('/api/ui/auth/logout');

        // replay do mesmo código é recusado
        $this->login($this->client, 'ana');
        $this->post('/api/ui/auth/mfa', ['code' => $next], 401);
    }

    public function testSessionsCanBeListedAndRevoked(): void
    {
        self::createAccount('ana', 'viewer');
        $this->login($this->client, 'ana');
        $other = $this->sessionCookie();
        $this->login($this->client, 'ana');
        $sessions = $this->get('/api/ui/me/sessions')['items'];
        self::assertCount(2, $sessions);
        $foreign = array_values(array_filter($sessions, static fn (array $s): bool => !$s['current']))[0];
        $this->post('/api/ui/me/sessions/'.$foreign['id'], null, 200, 'DELETE');
        $this->useSession($other);
        $this->client->request('GET', '/api/ui/me', server: self::UI);
        self::assertResponseStatusCodeSame(401);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri, server: self::UI);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|null $body @return array<string, mixed> */
    private function post(string $uri, ?array $body = null, int $expect = 200, string $method = 'POST'): array
    {
        $this->client->request($method, $uri, server: self::UI + ['CONTENT_TYPE' => 'application/json'],
            content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($expect, (string) $this->client->getResponse()->getContent());

        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function sessionCookie(): Cookie
    {
        $c = $this->client->getCookieJar()->get('bianchi_sid', '/api/ui');
        self::assertNotNull($c);

        return $c;
    }

    private function useSession(Cookie $cookie): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($cookie);
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
