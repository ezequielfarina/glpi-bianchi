<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ui;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * - Frequência configurada no painel vira o "expiration" devolvido ao GLPI Agent.
 * - Cada inventário registra se criou o ativo, atualizou ou não mudou nada.
 */
final class SettingsAndOutcomeTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testFrequencySettingIsSentToAgent(): void
    {
        $this->api('PUT', '/api/ui/settings', ['inventory.frequency_hours' => 6]);
        self::assertResponseIsSuccessful();

        $contact = $this->agent(['action' => 'contact', 'deviceid' => 'pc-x-2026-11-26-10-00-00', 'enabled-tasks' => ['inventory']]);
        self::assertSame(6, $contact['expiration']);

        $settings = $this->api('GET', '/api/ui/settings');
        self::assertSame(6, $settings['items']['inventory.frequency_hours']['value']);
        self::assertSame(24, $settings['items']['inventory.frequency_hours']['default']);
    }

    public function testInvalidSettingIsRejected(): void
    {
        $this->api('PUT', '/api/ui/settings', ['inventory.frequency_hours' => 0]);
        self::assertResponseStatusCodeSame(422);
        $this->api('PUT', '/api/ui/settings', ['nao.existe' => 1]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOutcomeTellsIfAssetWasAlreadyRegistered(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/inventory/computer-linux.json'), true, 512, \JSON_THROW_ON_ERROR);

        $this->agent($fixture);                         // 1º: não existia
        $this->agent($fixture);                         // 2º: idêntico
        $fixture['content']['operatingsystem']['kernel_version'] = '6.12.99-1';
        $this->agent($fixture);                         // 3º: mudou o kernel

        $items = $this->api('GET', '/api/ui/inventories')['items'];
        self::assertSame(['updated', 'unchanged', 'created'], array_column($items, 'outcome'));
        self::assertGreaterThanOrEqual(1, $items[0]['changes']);
    }

    public function testWakeupUnknownAgentIs404(): void
    {
        $this->api('POST', '/api/ui/agents/nao-existe/wakeup');
        self::assertResponseStatusCodeSame(404);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function agent(array $payload): array
    {
        $this->client->request('POST', '/', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_GLPI_AGENT_ID' => 'a3a1e9c4-0000-4000-8000-000000000002'], content: json_encode($payload, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|null $body @return array<string, mixed> */
    private function api(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer test-token',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
