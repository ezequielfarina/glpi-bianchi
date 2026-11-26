<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tools;

use App\Tests\Integration\Support\LogsIn;
use App\Tools\Message\RunNetworkScan;
use App\Tools\MessageHandler\RunNetworkScanHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Scanner de rede: permissões, validação, fila e execução do handler com um nmap
 * falso (tests/Fixtures/nmap/fake-nmap.sh, configurado em services.yaml when@test).
 */
final class ScannerApiTest extends WebTestCase
{
    use LogsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testViewerCannotUseTools(): void
    {
        $this->loginAs($this->client, 'viewer');
        $this->client->request('GET', '/api/ui/tools/scanner', server: self::UI);
        self::assertResponseStatusCodeSame(403);
    }

    public function testScanLifecycleWithFakeNmap(): void
    {
        $this->loginAs($this->client, 'editor');
        $info = $this->call('GET', '/api/ui/tools/scanner');
        self::assertSame('7.95', $info['nmap']);

        $this->call('POST', '/api/ui/tools/scanner/scans', ['target' => '8.8.8.0/24', 'profile' => 'discovery'], 422);
        $this->call('POST', '/api/ui/tools/scanner/scans', ['target' => '192.168.10.0/24', 'snmp_gateway' => '192.168.10.1'], 422); // falta comunidade
        $id = $this->call('POST', '/api/ui/tools/scanner/scans', ['target' => '192.168.10.9/24', 'profile' => 'standard'], 202)['id'];
        $scan = $this->call('GET', "/api/ui/tools/scanner/scans/$id");
        self::assertSame('queued', $scan['status']);
        self::assertSame('192.168.10.0/24', $scan['target']);

        // impressora manual com o IP que o "nmap" vai encontrar → correlação por IP
        $this->call('POST', '/api/ui/assets', ['type' => 'printer', 'name' => 'IMP-TESTE', 'attributes' => ['IP' => '192.168.10.30']], 201);

        self::getContainer()->get(RunNetworkScanHandler::class)(new RunNetworkScan($id));
        $scan = $this->call('GET', "/api/ui/tools/scanner/scans/$id");
        self::assertSame('done', $scan['status'], (string) $scan['error']);
        self::assertSame(2, $scan['hosts_up']);
        $byIp = array_column($scan['hosts'], null, 'ip');
        self::assertSame('00:0c:29:aa:bb:01', $byIp['192.168.10.20']['mac']);
        self::assertSame('PC-01', $byIp['192.168.10.20']['netbios_name']);
        self::assertSame('computer', $byIp['192.168.10.20']['device_type']);
        self::assertSame('printer', $byIp['192.168.10.30']['device_type']);
        self::assertSame('IMP-TESTE', $byIp['192.168.10.30']['asset_name']);
        self::assertSame('ip', $byIp['192.168.10.30']['match_by']);

        $this->client->request('GET', "/api/ui/tools/scanner/scans/$id/export", server: self::UI);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('192.168.10.20;00:0c:29:aa:bb:01', (string) $this->client->getResponse()->getContent());

        self::assertSame($id, $this->call('GET', '/api/ui/tools/scanner/scans')['items'][0]['id']);
        $this->call('DELETE', "/api/ui/tools/scanner/scans/$id");
        self::assertFalse(self::getContainer()->get(Connection::class)->fetchOne('SELECT 1 FROM network_scan WHERE id = ?', [$id]));
    }

    public function testQueuedScanCanBeCancelled(): void
    {
        $this->loginAs($this->client, 'admin');
        $id = $this->call('POST', '/api/ui/tools/scanner/scans', ['target' => '10.20.0.0/24', 'profile' => 'discovery'], 202)['id'];
        $this->call('DELETE', "/api/ui/tools/scanner/scans/$id", null, 422); // não exclui em andamento
        $this->call('POST', "/api/ui/tools/scanner/scans/$id/cancel");
        self::assertSame('cancelled', $this->call('GET', "/api/ui/tools/scanner/scans/$id")['status']);
        // handler ignora varredura cancelada
        self::getContainer()->get(RunNetworkScanHandler::class)(new RunNetworkScan($id));
        self::assertSame('cancelled', $this->call('GET', "/api/ui/tools/scanner/scans/$id")['status']);
    }

    /** @param array<string, mixed>|null $body @return array<string, mixed> */
    private function call(string $method, string $uri, ?array $body = null, int $expect = 200): array
    {
        $this->client->request($method, $uri, server: self::UI + ['CONTENT_TYPE' => 'application/json'], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($expect, (string) $this->client->getResponse()->getContent());

        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
