<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ui;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Endpoints da interface (/api/ui/*): autenticação Bearer e forma das respostas
 * após um inventário real passar pelo protocolo do agente (transporte sync em test).
 */
final class UiApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testRequiresToken(): void
    {
        $this->client->request('GET', '/api/ui/overview');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/ui/overview', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testInventoryShowsUpInOverviewSearchAndDetail(): void
    {
        $payload = (string) file_get_contents(__DIR__.'/../../Fixtures/inventory/computer-linux.json');
        $this->client->request('POST', '/', server: ['CONTENT_TYPE' => 'application/x-compress-zlib'], content: (string) gzcompress($payload));
        self::assertResponseIsSuccessful();

        $overview = $this->get('/api/ui/overview');
        self::assertSame(1, $overview['kpi']['assets']);
        self::assertCount(24, $overview['hourly']);
        self::assertSame('srv-lab01', $overview['recent'][0]['name']);
        self::assertSame('192.168.10.50', $overview['recent'][0]['ip']);

        // busca por MAC em formato Windows (traços, maiúsculas) e por IP
        foreach (['B0-26-28-AA-BB-01', '192.168.10.50', 'srv-lab', 'BX5Q2J2'] as $q) {
            $found = $this->get('/api/ui/assets?q='.urlencode($q));
            self::assertSame(1, $found['total'], "busca por \"$q\"");
        }
        self::assertSame(0, $this->get('/api/ui/assets?q=nao-existe')['total']);

        $uuid = $overview['recent'][0]['uuid'];
        $detail = $this->get('/api/ui/assets/'.$uuid);
        self::assertSame('PowerEdge R640', $detail['model']);
        self::assertCount(3, $detail['software']);
        self::assertSame('b0:26:28:aa:bb:01', $detail['networkPorts'][0]['mac_address']);
        self::assertCount(2, $detail['networkPorts'][0]['ips']);
        self::assertSame(16384, $detail['attributes']['memory_mib']);

        $this->client->request('GET', '/api/ui/assets/00000000-0000-0000-0000-000000000000', server: $this->auth());
        self::assertResponseStatusCodeSame(404);

        $agents = $this->get('/api/ui/agents');
        self::assertSame('srv-lab01', $agents['items'][0]['name']);
        self::assertSame('processed', $agents['items'][0]['last_status']);
    }

    /** @return array<string, mixed> */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri, server: $this->auth());
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer test-token', 'HTTP_ACCEPT' => 'application/json'];
    }
}
