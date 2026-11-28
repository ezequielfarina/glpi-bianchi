<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Auditoria 2026-11: endpoint público do agente só aceita redes permitidas (AGENT_ALLOWED_NETWORKS). */
final class AgentAccessTest extends WebTestCase
{
    public function testPublicInternetSourceIsRejected(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.7'],
            content: (string) json_encode(['action' => 'contact', 'deviceid' => 'intruso-2026-11-28-00-00-00'])
        );
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne("SELECT count(*) FROM agent WHERE device_id LIKE 'intruso%'"));
    }

    public function testPrivateNetworkSourceIsAccepted(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.168.110.40'],
            content: (string) json_encode(['action' => 'contact', 'deviceid' => 'pc-lan-2026-11-28-00-00-00', 'enabled-tasks' => ['inventory']])
        );
        self::assertResponseIsSuccessful();
    }
}
