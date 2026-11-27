<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tools;

use App\Tests\Integration\Support\LogsIn;
use App\Tools\Message\RunNetworkScan;
use App\Tools\MessageHandler\RunNetworkScanHandler;
use App\Tools\Service\ScheduleService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-0013: agendamentos → tick → varredura (nmap falso) → alertas de mudança;
 * canais só para admin e com segredo mascarado; conflitos e consultas.
 */
final class MonitorApiTest extends WebTestCase
{
    use LogsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testScheduleTickBaselineAndChangeAlerts(): void
    {
        $this->loginAs($this->client, 'editor');
        $this->call('POST', '/api/ui/tools/schedules', ['name' => 'X', 'target' => '10.0.0.0/16', 'profile' => 'standard', 'frequency' => 'daily', 'run_at' => '02:00'], 422);
        $this->call('POST', '/api/ui/tools/schedules', ['name' => 'X', 'target' => '192.168.10.0/24', 'frequency' => 'daily', 'run_at' => '25:00'], 422);
        $id = $this->call('POST', '/api/ui/tools/schedules', [
            'name' => 'Lab', 'target' => '192.168.10.0/24', 'profile' => 'standard', 'frequency' => 'interval', 'interval_hours' => 4,
            'snmp_gateway' => null, 'alerts' => ['new_ports' => true],
        ], 201)['id'];
        $list = $this->call('GET', '/api/ui/tools/schedules');
        self::assertTrue($list['items'][0]['alerts']['new_ports']);
        self::assertNotNull($list['items'][0]['next_run_at']);

        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("UPDATE scan_schedule SET next_run_at = now() - interval '1 minute' WHERE id = ?", [$id]);
        $tick = self::getContainer()->get(ScheduleService::class)->tick();
        self::assertStringContainsString('varredura #', $tick[0]['result']);
        $scan1 = (int) $db->fetchOne('SELECT max(id) FROM network_scan WHERE schedule_id = ?', [$id]);

        $handler = self::getContainer()->get(RunNetworkScanHandler::class);
        $handler(new RunNetworkScan($scan1));
        $alerts = $this->call('GET', '/api/ui/tools/alerts');
        self::assertSame(['baseline'], array_column($alerts['items'], 'kind'));

        // 2ª execução idêntica → nenhuma mudança; 3ª depois de "esquecer" o .30 na anterior → dispositivo novo
        $scan2 = $this->call('POST', "/api/ui/tools/schedules/$id/run", null, 202)['scan_id'];
        $handler(new RunNetworkScan($scan2));
        self::assertCount(1, $this->call('GET', '/api/ui/tools/alerts')['items']);
        $db->executeStatement("DELETE FROM network_scan_host WHERE scan_id = ? AND ip = '192.168.10.30'", [$scan2]);
        $scan3 = $this->call('POST', "/api/ui/tools/schedules/$id/run", null, 202)['scan_id'];
        $handler(new RunNetworkScan($scan3));
        $alerts = $this->call('GET', '/api/ui/tools/alerts', null, 200, ['kind' => 'new_device']);
        self::assertCount(1, $alerts['items']);
        self::assertSame('192.168.10.30', $alerts['items'][0]['ip']);
        self::assertGreaterThanOrEqual(1, $this->call('GET', '/api/ui/tools/alerts/count')['warning']);

        $n = $this->call('POST', '/api/ui/tools/alerts/ack', ['ids' => [$alerts['items'][0]['id']]])['acknowledged'];
        self::assertSame(1, $n);
        self::assertCount(0, $this->call('GET', '/api/ui/tools/alerts', null, 200, ['kind' => 'new_device'])['items']);

        $this->call('DELETE', "/api/ui/tools/schedules/$id");
        self::assertNull($db->fetchOne('SELECT schedule_id FROM network_scan WHERE id = ?', [$scan1])); // histórico mantido
    }

    public function testChannelsAreAdminOnlyAndSecretIsMasked(): void
    {
        $this->loginAs($this->client, 'editor');
        $this->call('GET', '/api/ui/tools/channels', null, 403);

        $this->loginAs($this->client, 'admin');
        $this->call('POST', '/api/ui/tools/channels', ['name' => 'T', 'kind' => 'telegram', 'target' => 'abc', 'chat_id' => '1'], 422);
        $this->call('POST', '/api/ui/tools/channels', ['name' => 'Teams', 'kind' => 'teams', 'target' => 'https://x.logic.azure.com/workflows/a/b?sig=SEGREDO123'], 201);
        $raw = json_encode($this->call('GET', '/api/ui/tools/channels'));
        self::assertStringNotContainsString('SEGREDO123', (string) $raw);
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        self::assertStringNotContainsString('SEGREDO123', (string) $db->fetchOne('SELECT target FROM notification_channel'));
    }

    public function testConflictsAndLookups(): void
    {
        $this->loginAs($this->client, 'viewer');
        $this->call('GET', '/api/ui/tools/conflicts', null, 403);

        $this->loginAs($this->client, 'editor');
        $r = $this->call('GET', '/api/ui/tools/conflicts');
        self::assertArrayHasKey('mac_duplicates', $r);
        self::assertArrayHasKey('arp', $r);

        $macs = $this->call('POST', '/api/ui/tools/net/mac', ['macs' => "00-09-0F-FE-00-01\nxyz"])['items'];
        self::assertSame('00:09:0f:fe:00:01', $macs[0]['mac']);
        self::assertStringContainsString('FortiClient', implode(' ', $macs[0]['flags']));
        self::assertFalse($macs[1]['valid']);

        $this->call('POST', '/api/ui/tools/net/ping', ['host' => '-oX /tmp/x'], 422);
        $this->call('POST', '/api/ui/tools/net/ports', ['host' => '127.0.0.1', 'ports' => '1-100'], 422);
        $this->call('POST', '/api/ui/tools/net/dns', ['name' => 'a', 'type' => 'A', 'servers' => ['1.1.1.1;id']], 422);
        $asn = $this->call('POST', '/api/ui/tools/net/asn', ['query' => '192.168.1.1']);
        self::assertSame('Privado (RFC 1918)', $asn['special']);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $query
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, ?array $body = null, int $status = 200, array $query = []): array
    {
        $this->client->request($method, $uri.([] === $query ? '' : '?'.http_build_query($query)), server: self::UI + ['CONTENT_TYPE' => 'application/json'], content: null === $body ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        $c = (string) $this->client->getResponse()->getContent();

        return '' === $c ? [] : (array) json_decode($c, true);
    }
}
