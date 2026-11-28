<?php

declare(strict_types=1);

namespace App\Tests\Integration\Wifi;

use App\Tests\Integration\Support\LogsIn;
use App\Tests\Support\FakeUnifi;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-0020: controller UniFi → sites e redes → identidade do MAC → filtro de MAC no UniFi,
 * preservando o que já estava lá, sem tocar na senha do Wi-Fi e sem vazar credencial.
 */
final class WifiAccessTest extends WebTestCase
{
    use LogsIn;

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        FakeUnifi::reset();
        $this->client = self::createClient();
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $this->db = $db;
    }

    public function testControllerIsAdminOnlyAndSecretNeverLeaves(): void
    {
        $this->loginAs($this->client, 'editor');
        $this->call('POST', '/api/ui/wifi/controllers', $this->controllerBody(), 403);
        $this->call('GET', '/api/ui/wifi/controllers', null, 403);

        $this->client->restart();
        $this->loginAs($this->client, 'admin');
        $this->call('POST', '/api/ui/wifi/controllers', ['name' => 'X', 'base_url' => 'http://unifi.test:8443', 'auth_mode' => 'local', 'username' => 'u', 'secret' => 's'], 422);
        $this->call('POST', '/api/ui/wifi/controllers', ['verify_tls' => false] + $this->controllerBody(), 422); // sem CA e sem pin
        $av = $this->call('POST', '/api/ui/wifi/controllers', ['username' => 'Avatar integracao'] + $this->controllerBody(), 422);
        self::assertStringContainsString('Avatar', (string) ($av['errors']['username'] ?? ''));
        $id = $this->controller();

        $stored = (string) $this->db->fetchOne('SELECT secret FROM unifi_controller WHERE id = ?', [$id]);
        self::assertStringNotContainsString(FakeUnifi::PASS, $stored);
        $list = $this->call('GET', '/api/ui/wifi/controllers');
        self::assertTrue($list['items'][0]['has_secret']);
        self::assertArrayNotHasKey('secret', $list['items'][0]);

        // editar sem reenviar a senha mantém a credencial
        $this->call('PUT', "/api/ui/wifi/controllers/$id", ['secret' => null] + $this->controllerBody());
        $test = $this->call('POST', "/api/ui/wifi/controllers/$id/test");
        self::assertTrue($test['ok'], (string) $test['error']);
        self::assertSame('classic', $test['platform']);
        self::assertSame('9.0.114', $test['version']);
        self::assertSame(2, $test['sites']);

        // senha errada: mensagem útil, sem 500
        $this->call('PUT', "/api/ui/wifi/controllers/$id", ['secret' => 'errada'] + $this->controllerBody());
        $bad = $this->call('POST', "/api/ui/wifi/controllers/$id/test");
        self::assertFalse($bad['ok']);
        self::assertStringContainsString('usuário ou senha incorretos', (string) $bad['error']);

        $audit = (string) $this->db->fetchOne("SELECT string_agg(coalesce(details::text, ''), ' ') FROM audit_log WHERE module = 'wifi'");
        self::assertStringNotContainsString(FakeUnifi::PASS, $audit);
        self::assertStringNotContainsString('errada', $audit);
    }

    public function testApiKeyOnUnifiOs(): void
    {
        $this->loginAs($this->client, 'admin');
        $id = (int) $this->call('POST', '/api/ui/wifi/controllers', ['name' => 'UOS', 'base_url' => 'https://unifi.test:11443', 'auth_mode' => 'api_key',
            'secret' => FakeUnifi::KEY, 'verify_tls' => true], 201)['id'];
        $test = $this->call('POST', "/api/ui/wifi/controllers/$id/test");
        self::assertTrue($test['ok'], (string) $test['error']);
        self::assertSame('unifi_os', $test['platform']);
        self::assertContains('/proxy/network/api/self/sites', array_column(FakeUnifi::$calls, 'path'));
    }

    public function testSyncApplyPreservesUnknownMacsAndNeverTouchesPassphrase(): void
    {
        $this->loginAs($this->client, 'admin');
        $cid = $this->controller();
        $sync = $this->call('POST', "/api/ui/wifi/sync/$cid");
        self::assertSame(['sites' => 2, 'wlans' => 3], ['sites' => $sync['sites'], 'wlans' => $sync['wlans']]);

        $tree = $this->call('GET', '/api/ui/wifi/tree');
        self::assertStringNotContainsString(FakeUnifi::PASSPHRASE, (string) json_encode($tree));
        $corp = $this->wlanId('BIANCHI-CORP');
        $guest = $this->wlanId('BIANCHI-VISITANTE');
        $deny = $this->wlanId('NORTE-BLOQUEIO');
        self::assertSame(2, $this->treeWlan($tree, $corp)['unknown']);

        // MAC em qualquer formato, aplicado na hora; os 2 MACs que já estavam no UniFi continuam
        $r = $this->call('POST', '/api/ui/wifi/identities', ['mac' => 'AA-BB-CC-00-00-10', 'person_name' => 'Maria Souza', 'department' => 'Financeiro',
            'registration' => '1234', 'device_kind' => 'phone', 'device_brand' => 'Samsung', 'device_model' => 'Galaxy S23', 'wlan_ids' => [$corp], 'apply' => true], 201);
        $maria = (int) $r['id'];
        self::assertNull($r['applied'][0]['error']);
        self::assertSame(['aa:bb:cc:00:00:10'], $r['applied'][0]['added']);
        self::assertSame(3, $r['applied'][0]['total']);
        self::assertSame(['aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:02', 'aa:bb:cc:00:00:10'], $this->remote('default', 'w1'));
        self::assertSame(FakeUnifi::PASSPHRASE, FakeUnifi::$sites['default']['wlans']['w1']['x_passphrase']);
        $put = array_values(array_filter(FakeUnifi::$calls, static fn (array $c): bool => 'PUT' === $c['method']));
        self::assertSame(['mac_filter_enabled', 'mac_filter_list', 'mac_filter_policy'], self::sortedKeys($put[0]['body']));
        self::assertSame('Maria Souza — Samsung Galaxy S23', FakeUnifi::$sites['default']['users']['aa:bb:cc:00:00:10']['name']);

        // mesmo MAC de novo: recusado
        $this->call('POST', '/api/ui/wifi/identities', ['mac' => 'aabbcc000010', 'person_name' => 'Outro'], 422);

        // MAC que já estava no UniFi: identifica sem regravar a rede
        $detail = $this->call('GET', "/api/ui/wifi/wlans/$corp");
        self::assertStringNotContainsString(FakeUnifi::PASSPHRASE, (string) json_encode($detail));
        self::assertSame(['aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:02'], array_column($detail['unknown'], 'mac'));
        $this->call('POST', "/api/ui/wifi/wlans/$corp/adopt", ['mac' => 'aa:bb:cc:00:00:01', 'person_name' => 'João', 'device_kind' => 'notebook'], 201);
        self::assertSame(['aa:bb:cc:00:00:02'], array_column($this->call('GET', "/api/ui/wifi/wlans/$corp")['unknown'], 'mac'));

        // revogar: sai só o MAC dela
        $rev = $this->call('POST', "/api/ui/wifi/identities/$maria/revoke", ['apply' => true]);
        self::assertSame(['aa:bb:cc:00:00:10'], $rev['applied'][0]['removed']);
        self::assertSame(['aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:02'], $this->remote('default', 'w1'));
        $this->call('DELETE', "/api/ui/wifi/identities/$maria");

        // rede com filtro desligado: grava e avisa; rede em modo bloqueio: recusa
        $g = $this->call('POST', '/api/ui/wifi/identities', ['mac' => '02:11:22:33:44:55', 'person_name' => 'Visitante', 'wlan_ids' => [$guest], 'apply' => true], 201);
        self::assertStringContainsString('DESLIGADO', $g['applied'][0]['warnings'][0]);
        $this->call('POST', '/api/ui/wifi/identities', ['mac' => '00:11:22:33:44:66', 'person_name' => 'X', 'wlan_ids' => [$deny]], 422);
        self::assertTrue($this->call('GET', '/api/ui/wifi/lookup?mac=02-11-22-33-44-55')['randomized']);

        // removido direto no UniFi → sincronização marca erro
        FakeUnifi::$sites['default']['wlans']['w1']['mac_filter_list'] = ['aa:bb:cc:00:00:02'];
        self::assertSame(1, $this->call('POST', "/api/ui/wifi/sync/$cid")['drift']);

        $actions = $this->db->fetchFirstColumn("SELECT action FROM audit_log WHERE module = 'wifi' ORDER BY id");
        self::assertContains('mac_identity.created', $actions);
        self::assertContains('mac_identity.revoked', $actions);
        self::assertContains('mac_identity.adopted', $actions);
    }

    public function testPendingRetryExpiryAndFilterSwitch(): void
    {
        $this->loginAs($this->client, 'admin');
        $cid = $this->controller();
        $this->call('POST', "/api/ui/wifi/sync/$cid");
        $corp = $this->wlanId('BIANCHI-CORP');
        $guest = $this->wlanId('BIANCHI-VISITANTE');

        FakeUnifi::$down = true;
        $r = $this->call('POST', '/api/ui/wifi/identities', ['mac' => 'aa:bb:cc:00:00:20', 'person_name' => 'Ana', 'wlan_ids' => [$corp], 'apply' => true], 201);
        self::assertStringContainsString('recusada', (string) $r['applied'][0]['error']);
        self::assertSame('pending', $this->db->fetchOne('SELECT state FROM mac_wlan_assignment WHERE identity_id = ?', [$r['id']]));

        FakeUnifi::$down = false;
        self::assertSame(0, $this->runSync());
        self::assertSame('synced', $this->db->fetchOne('SELECT state FROM mac_wlan_assignment WHERE identity_id = ?', [$r['id']]));
        self::assertContains('aa:bb:cc:00:00:20', $this->remote('default', 'w1'));

        // validade vencida: o timer revoga e tira da rede
        $this->db->executeStatement("UPDATE mac_identity SET valid_until = now() - interval '1 minute' WHERE id = ?", [$r['id']]);
        self::assertSame(0, $this->runSync());
        self::assertSame('revoked', $this->db->fetchOne('SELECT status FROM mac_identity WHERE id = ?', [$r['id']]));
        self::assertNotContains('aa:bb:cc:00:00:20', $this->remote('default', 'w1'));

        // conferência depois de gravar: UniFi "aceita" mas não grava → erro, não "synced"
        FakeUnifi::$ignoreWrites = true;
        $x = $this->call('POST', '/api/ui/wifi/identities', ['mac' => 'aa:bb:cc:00:00:30', 'person_name' => 'Rui', 'wlan_ids' => [$corp], 'apply' => true], 201);
        self::assertStringContainsString('não confere', (string) $x['applied'][0]['error']);
        FakeUnifi::$ignoreWrites = false;

        // ligar filtro: exige o nome exato e é só admin
        $this->call('POST', '/api/ui/wifi/identities', ['mac' => 'aa:bb:cc:00:00:40', 'person_name' => 'Visit', 'wlan_ids' => [$guest]], 201);
        $this->call('POST', "/api/ui/wifi/wlans/$guest/filter", ['confirm' => 'errado'], 422);
        $on = $this->call('POST', "/api/ui/wifi/wlans/$guest/filter", ['confirm' => 'BIANCHI-VISITANTE']);
        self::assertTrue($on['filter_enabled']);
        self::assertSame(['aa:bb:cc:00:00:40'], $this->remote('default', 'w2'));

        $this->client->restart();
        $this->loginAs($this->client, 'editor');
        $this->call('POST', "/api/ui/wifi/wlans/$guest/filter", ['confirm' => 'BIANCHI-VISITANTE'], 403);
        $this->call('POST', "/api/ui/wifi/wlans/$corp/apply");
        $this->client->restart();
        $this->loginAs($this->client, 'viewer');
        $this->call('POST', "/api/ui/wifi/wlans/$corp/apply", null, 403);
        self::assertNotEmpty($this->call('GET', '/api/ui/wifi/tree')['items']);
    }

    public function testCredentialIsTestedBeforeSavingAndSiteSync(): void
    {
        $this->loginAs($this->client, 'admin');
        $cid = $this->controller();
        $before = (string) $this->db->fetchOne('SELECT secret FROM unifi_controller WHERE id = ?', [$cid]);

        // senha errada: recusada e a antiga continua valendo
        $this->call('POST', "/api/ui/wifi/controllers/$cid/credential", ['secret' => FakeUnifi::PASS, 'username' => 'Avatar integracao'], 422);
        $bad = $this->call('POST', "/api/ui/wifi/controllers/$cid/credential", ['secret' => 'digitada-errada'], 422);
        self::assertStringContainsString('nada foi alterado', (string) ($bad['errors']['secret'] ?? ''));
        self::assertSame($before, $this->db->fetchOne('SELECT secret FROM unifi_controller WHERE id = ?', [$cid]));
        self::assertTrue($this->call('POST', "/api/ui/wifi/controllers/$cid/test")['ok']);

        // senha nova trocada no UniFi: aceita, regrava cifrado
        FakeUnifi::$pass = 'senha-nova-forte-2026';
        self::assertFalse($this->call('POST', "/api/ui/wifi/controllers/$cid/test")['ok']);
        $ok = $this->call('POST', "/api/ui/wifi/controllers/$cid/credential", ['secret' => 'senha-nova-forte-2026']);
        self::assertTrue($ok['ok']);
        self::assertSame(2, $ok['sites']);
        self::assertNotSame($before, $this->db->fetchOne('SELECT secret FROM unifi_controller WHERE id = ?', [$cid]));
        self::assertTrue($this->call('POST', "/api/ui/wifi/controllers/$cid/test")['ok']);
        self::assertStringNotContainsString('senha-nova-forte-2026', (string) $this->db->fetchOne("SELECT string_agg(coalesce(details::text, ''), ' ') FROM audit_log"));

        // sincronizar um site só: rede nova no UniFi aparece, a removida some
        $this->call('POST', "/api/ui/wifi/sync/$cid");
        $site = (int) $this->db->fetchOne("SELECT id FROM unifi_site WHERE site_ref = 'default'");
        FakeUnifi::$sites['default']['wlans']['w9'] = ['_id' => 'w9', 'name' => 'NOVA-REDE', 'enabled' => true, 'security' => 'wpapsk', 'x_passphrase' => FakeUnifi::PASSPHRASE,
            'mac_filter_enabled' => true, 'mac_filter_policy' => 'allow', 'mac_filter_list' => ['aa:bb:cc:00:00:99']];
        unset(FakeUnifi::$sites['default']['wlans']['w2']);
        $r = $this->call('POST', "/api/ui/wifi/sites/$site/sync");
        self::assertSame(['site' => 'Matriz', 'wlans' => 2, 'drift' => 0], $r);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM unifi_wlan WHERE name = 'NOVA-REDE' AND present"));
        self::assertFalse((bool) $this->db->fetchOne("SELECT present FROM unifi_wlan WHERE name = 'BIANCHI-VISITANTE'"));
        $this->call('POST', '/api/ui/wifi/sites/999999/sync', null, 404);

        $this->client->restart();
        $this->loginAs($this->client, 'editor');
        $this->call('POST', "/api/ui/wifi/controllers/$cid/credential", ['secret' => 'x'], 403);
        $this->call('POST', "/api/ui/wifi/sites/$site/sync");
    }

    private function runSync(): int
    {
        $app = new Application(self::$kernel ?? self::bootKernel());
        $t = new CommandTester($app->find('app:wifi:sync'));

        return $t->execute(['--no-sync' => true]);
    }

    /** @return array<string, mixed> */
    private function controllerBody(): array
    {
        return ['name' => 'UniFi VM', 'base_url' => 'https://unifi.test:8443', 'auth_mode' => 'local', 'username' => FakeUnifi::USER, 'secret' => FakeUnifi::PASS, 'verify_tls' => true];
    }

    private function controller(): int
    {
        return (int) $this->call('POST', '/api/ui/wifi/controllers', $this->controllerBody(), 201)['id'];
    }

    private function wlanId(string $name): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM unifi_wlan WHERE name = ?', [$name]);
    }

    /** @return list<string> */
    private function remote(string $site, string $wlan): array
    {
        $l = array_map('strtolower', FakeUnifi::$sites[$site]['wlans'][$wlan]['mac_filter_list']);
        sort($l);

        return $l;
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<string, mixed>
     */
    private function treeWlan(array $tree, int $id): array
    {
        foreach ($tree['items'] as $c) {
            foreach ($c['sites'] as $s) {
                foreach ($s['wlans'] as $w) {
                    if ($w['id'] === $id) {
                        return $w;
                    }
                }
            }
        }
        self::fail("rede $id fora da árvore");
    }

    /** @return list<string> */
    private static function sortedKeys(mixed $a): array
    {
        $k = array_keys((array) $a);
        sort($k);

        return $k;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, ?array $body = null, int $status = 200): array
    {
        $this->client->request($method, $uri, server: self::UI + ['CONTENT_TYPE' => 'application/json'], content: null === $body ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        $c = (string) $this->client->getResponse()->getContent();

        /** @var array<string, mixed> */
        return '' === $c ? [] : (array) json_decode($c, true);
    }
}
