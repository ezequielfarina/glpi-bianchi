<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Tests\Integration\Support\LogsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** ADR-0015: ações da interface entram na trilha (objeto lido antes da exclusão, sem segredos, só 2xx). */
final class AuditTrailTest extends WebTestCase
{
    use LogsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testWritesAreAuditedAndTrailIsAppendOnly(): void
    {
        $this->loginAs($this->client, 'editor');
        $id = $this->call('POST', '/api/ui/stock/items', ['name' => 'Teclado Auditoria', 'category' => 'Teclado'], 201)['id'];
        $this->call('POST', '/api/ui/stock/items', ['name' => '', 'category' => ''], 422);
        $this->call('DELETE', "/api/ui/stock/items/$id");
        $this->call('GET', '/api/ui/audit/actions', null, 403);

        $this->loginAs($this->client, 'admin');
        $trail = $this->call('GET', '/api/ui/audit/actions', null, 200, ['module' => 'stock']);
        $actions = array_column($trail['items'], 'action');
        self::assertSame(['stock.item_deleted', 'stock.item_created'], \array_slice($actions, 0, 2));
        self::assertSame('Teclado Auditoria', $trail['items'][0]['subject']);   // lido antes de excluir
        self::assertSame((string) $id, $trail['items'][1]['entity_id']);
        self::assertCount(2, array_filter($actions, static fn (string $a): bool => str_starts_with($a, 'stock.item_'))); // o 422 não entra

        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $this->expectExceptionMessageMatches('/somente inclusão/');
        $db->executeStatement("UPDATE audit_log SET actor = 'x'");
    }

    public function testSecretsAreMasked(): void
    {
        $this->loginAs($this->client, 'admin');
        $this->call('POST', '/api/ui/tools/channels', ['name' => 'Webhook auditoria', 'kind' => 'webhook', 'target' => 'https://hooks.example.com/segredo-123', 'min_severity' => 'warning'], 201);
        $item = $this->call('GET', '/api/ui/audit/actions', null, 200, ['q' => 'Webhook auditoria'])['items'][0];
        self::assertSame('channel.created', $item['action']);
        self::assertSame('***', $item['details']['request']['target']);
        self::assertStringNotContainsString('segredo-123', (string) json_encode($item));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
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
