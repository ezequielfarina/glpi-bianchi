<?php

declare(strict_types=1);

namespace App\Tests\Integration\Vpn;

use App\Tests\Integration\Support\LogsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** ADR-0022: importar a config de dois FortiGates, casar os lados do túnel, diagnosticar e documentar — sem guardar segredo. */
final class VpnImportTest extends WebTestCase
{
    use LogsIn;

    private const string DIR = __DIR__.'/../../Fixtures/fortigate/';

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $this->db = $db;
    }

    private function device(string $name, string $branch): int
    {
        $b = (int) $this->db->fetchOne("INSERT INTO branch (name, kind, networks, active, created_at, updated_at) VALUES (?, 'branch', '[]', true, now(), now()) RETURNING id", [$branch]);

        return (int) $this->db->fetchOne(
            'INSERT INTO dcim_device (name, branch_id, device_type_id, role_id, status, created_at, updated_at)
             VALUES (?, ?, (SELECT min(id) FROM dcim_device_type), (SELECT min(id) FROM dcim_device_role), \'active\', now(), now()) RETURNING id',
            [$name, $b]
        );
    }

    public function testImportPairDiagnoseAndDocument(): void
    {
        $this->loginAs($this->client, 'editor');
        $mtz = $this->device('FGT-MATRIZ', 'Matriz VPN');
        $cvl = $this->device('FGT-CVL', 'Cascavel VPN');
        $matriz = (string) file_get_contents(self::DIR.'matriz.conf');

        // prévia não grava e avisa dos segredos descartados
        $pv = $this->call('POST', '/api/ui/vpn/import/preview', ['device_id' => $mtz, 'config_text' => $matriz]);
        self::assertSame('FGT-MATRIZ', $pv['hostname']);
        self::assertSame(['new', 'new', 'new'], array_column($pv['tunnels'], 'action'));
        self::assertStringContainsString('descartados', implode(' ', $pv['warnings']));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM vpn_tunnel'));

        // equipamento trocado: avisa (não bloqueia)
        $wrong = $this->call('POST', '/api/ui/vpn/import/preview', ['device_id' => $cvl, 'config_text' => $matriz]);
        self::assertStringContainsString('"FGT-MATRIZ"', implode(' ', $wrong['warnings']));

        $r = $this->call('POST', '/api/ui/vpn/import', ['device_id' => $mtz, 'config_text' => $matriz]);
        self::assertSame(['created' => 3, 'updated' => 0, 'unchanged' => 0, 'missing' => 0], array_intersect_key($r, array_flip(['created', 'updated', 'unchanged', 'missing'])));
        $this->call('POST', '/api/ui/vpn/import', ['device_id' => $cvl, 'config_text' => (string) file_get_contents(self::DIR.'cascavel-show.txt')]);

        // nenhum segredo no banco nem na auditoria
        $dump = (string) $this->db->fetchOne("SELECT string_agg(t::text, ' ') FROM vpn_tunnel t").(string) $this->db->fetchOne("SELECT string_agg(p::text, ' ') FROM vpn_phase2 p")
            .(string) $this->db->fetchOne("SELECT string_agg(coalesce(details::text, ''), ' ') FROM audit_log WHERE module = 'vpn'");
        foreach (['DONOTSTORE', 'SIDESECRET', 'FORTICLIENTSECRET', 'ADMINHASH', 'BEGIN ENCRYPTED'] as $bad) {
            self::assertStringNotContainsString($bad, $dump, $bad);
        }
        self::assertContains('vpn.imported', $this->db->fetchFirstColumn("SELECT action FROM audit_log WHERE module = 'vpn'"));

        // pareamento automático Matriz VPN-CVL ↔ Cascavel VPN-MATRIZ e diagnóstico
        $list = array_column($this->call('GET', '/api/ui/vpn/tunnels')['items'], null, 'name');
        self::assertSame('VPN-MATRIZ', $list['VPN-CVL']['peer_tunnel']);
        self::assertSame('VPN-CVL', $list['VPN-MATRIZ']['peer_tunnel']);
        self::assertNull($list['VPN-BANCO']['peer_tunnel'], 'parceiro externo não está no inventário');
        $d = $this->call('GET', '/api/ui/vpn/tunnels/'.$list['VPN-CVL']['id']);
        self::assertSame('error', $d['compat']['verdict']);
        self::assertStringContainsString('seletores sem espelho', implode("\n", array_column($d['compat']['checks'], 'msg')));
        self::assertSame(['192.168.30.0/24', '192.168.31.0/24'], $d['routes']);
        self::assertContains('192.168.10.0/24', array_column($d['networks'], 'net'));

        // documentação: PSK não, só onde ela está
        $id = $list['VPN-BANCO']['id'];
        $e = $this->call('PUT', "/api/ui/vpn/tunnels/$id", ['psk_location' => 'kQ0jCVLSECRETDONOTSTOREabcdef1234567=='], 422);
        self::assertStringContainsString('ONDE', (string) $e['errors']['psk_location']);
        $sup = (int) $this->db->fetchOne("INSERT INTO supplier (name, active, created_at, updated_at) VALUES ('Banco Parceiro', true, now(), now()) RETURNING id");
        $this->call('PUT', "/api/ui/vpn/tunnels/$id", ['status' => 'active', 'peer_label' => 'Banco Parceiro — cobrança', 'supplier_id' => $sup,
            'psk_location' => 'Cofre TI › VPN › Banco', 'description' => 'Arquivo de cobrança CNAB']);
        $doc = $this->call('GET', "/api/ui/vpn/tunnels/$id");
        self::assertSame(['Banco Parceiro', 'Cofre TI › VPN › Banco'], [$doc['supplier'], $doc['psk_location']]);

        // reimportar: inalterados; túnel que saiu da config fica marcado ausente, documentação preservada
        $semBanco = (string) preg_replace('/    edit "VPN-BANCO".*?    next\n/s', '', $matriz, 1);
        $r2 = $this->call('POST', '/api/ui/vpn/import', ['device_id' => $mtz, 'config_text' => $semBanco]);
        self::assertSame([0, 1], [$r2['created'], $r2['missing']]);
        self::assertSame(2, $r2['unchanged'] + $r2['updated']);
        self::assertFalse($this->call('GET', "/api/ui/vpn/tunnels/$id")['present']);
        self::assertSame('Cofre TI › VPN › Banco', $this->call('GET', "/api/ui/vpn/tunnels/$id")['psk_location']);

        // texto sem túnel → 422 com orientação
        $bad = $this->call('POST', '/api/ui/vpn/import/preview', ['device_id' => $mtz, 'config_text' => "config system global\nset hostname X\nend"], 422);
        self::assertStringContainsString('show vpn ipsec phase1-interface', (string) $bad['errors']['config_text']);

        // perfil Leitura não importa nem edita
        $this->client->restart();
        $this->loginAs($this->client, 'viewer');
        $this->call('POST', '/api/ui/vpn/import', ['device_id' => $mtz, 'config_text' => $matriz], 403);
        $this->call('PUT', "/api/ui/vpn/tunnels/$id", ['status' => 'disabled'], 403);
        self::assertCount(4, $this->call('GET', '/api/ui/vpn/tunnels')['items']);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, ?array $body = null, int $status = 200): array
    {
        $this->client->request($method, $uri, server: self::UI + ['CONTENT_TYPE' => 'application/json'], content: null === $body ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame($status, mb_substr((string) $this->client->getResponse()->getContent(), 0, 3000));
        $c = (string) $this->client->getResponse()->getContent();

        /** @var array<string, mixed> */
        return '' === $c ? [] : (array) json_decode($c, true);
    }
}
