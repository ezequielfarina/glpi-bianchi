<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stock;

use App\Tests\Integration\Support\LogsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-0014: nota fiscal → entrada (patrimônio por unidade) → saída para filial → ajuste;
 * saldo nunca negativo, nota duplicada recusada, exclusão bloqueada quando algo já saiu.
 */
final class StockApiTest extends WebTestCase
{
    use LogsIn;

    private KernelBrowser $client;
    private int $supplier;
    private int $branch;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $this->supplier = (int) $db->fetchOne("INSERT INTO supplier (name, active, created_at, updated_at) VALUES ('Fornecedor Teste', true, now(), now()) RETURNING id");
        $this->branch = (int) $db->fetchOne("INSERT INTO branch (name, kind, networks, active, created_at, updated_at) VALUES ('Filial Teste', 'branch', '[]', true, now(), now()) RETURNING id");
    }

    public function testInvoiceInOutAdjustFlow(): void
    {
        $this->loginAs($this->client, 'editor');
        $mouse = $this->call('POST', '/api/ui/stock/items', ['name' => 'Mouse USB', 'category' => 'Mouse', 'min_quantity' => 10], 201)['id'];
        $monitor = $this->call('POST', '/api/ui/stock/items', ['name' => 'Monitor 24', 'category' => 'Monitor', 'tracked' => true, 'type' => 'monitor', 'min_quantity' => 2], 201)['id'];
        $this->call('POST', '/api/ui/stock/items', ['name' => 'CPU', 'category' => 'CPU', 'tracked' => true], 422);
        self::assertSame(2, $this->call('GET', '/api/ui/stock/low-count')['low']);

        $nf = $this->call('POST', '/api/ui/stock/invoices', [
            'supplier_id' => $this->supplier, 'number' => '000777', 'issue_date' => date('Y-m-d'), 'total' => '1.550,00',
            'items' => [
                ['stock_item_id' => $mouse, 'quantity' => 20, 'unit_price' => '25,00'],
                ['stock_item_id' => $monitor, 'quantity' => 2, 'unit_price' => '500', 'serials' => "SER-1\nSER-2"],
            ],
        ], 201)['id'];
        $this->call('POST', '/api/ui/stock/invoices', ['supplier_id' => $this->supplier, 'number' => '777', 'issue_date' => date('Y-m-d'), 'total' => '1',
            'items' => [['stock_item_id' => $mouse, 'quantity' => 1, 'unit_price' => '1']]], 422);

        $detail = $this->call('GET', "/api/ui/stock/invoices/$nf");
        self::assertSame('777', $detail['number']);
        self::assertSame('1500.00', $detail['items_total']);
        self::assertSame('50.00', $detail['difference']);
        self::assertCount(2, $detail['assets']);
        self::assertSame('in_stock', $detail['assets'][0]['status']);

        $this->call('POST', "/api/ui/stock/invoices/$nf/status", ['status' => 'sent']);
        self::assertSame('sent', $this->call('GET', "/api/ui/stock/invoices/$nf")['status']);
        self::assertCount(0, $this->call('GET', '/api/ui/stock/invoices', null, 200, ['status' => 'registered'])['items']);

        $unit = $this->call('GET', "/api/ui/stock/items/$monitor")['units'][0]['uuid'];
        $this->call('POST', '/api/ui/stock/out', ['branch_id' => $this->branch, 'lines' => [['stock_item_id' => $mouse, 'quantity' => 21]]], 422);
        $this->call('POST', '/api/ui/stock/out', ['branch_id' => $this->branch, 'recipient' => 'Ana', 'lines' => [
            ['stock_item_id' => $mouse, 'quantity' => 15],
            ['stock_item_id' => $monitor, 'assets' => [$unit]],
        ]], 201);
        $asset = $this->call('GET', "/api/ui/assets/$unit");
        self::assertSame('in_use', $asset['status']);

        $items = array_column($this->call('GET', '/api/ui/stock/items')['items'], null, 'id');
        self::assertSame(5, $items[$mouse]['balance']);
        self::assertTrue($items[$mouse]['low']);
        self::assertSame(5, $items[$mouse]['to_buy']);
        self::assertSame(1, $items[$monitor]['balance']);

        $this->call('POST', '/api/ui/stock/adjust', ['stock_item_id' => $mouse, 'quantity' => -9, 'reason' => 'contagem'], 422);
        $this->call('POST', '/api/ui/stock/adjust', ['stock_item_id' => $mouse, 'quantity' => -1, 'reason' => 'defeito'], 201);
        $cons = $this->call('GET', '/api/ui/stock/consumption');
        self::assertSame(16, $cons['branches'][0]['quantity']);

        // algo já saiu → não exclui; nota nova intacta → exclui e desfaz a entrada
        $this->call('DELETE', "/api/ui/stock/invoices/$nf", null, 422);
        $nf2 = $this->call('POST', '/api/ui/stock/invoices', ['supplier_id' => $this->supplier, 'number' => '778', 'issue_date' => date('Y-m-d'), 'total' => '500',
            'items' => [['stock_item_id' => $monitor, 'quantity' => 1, 'unit_price' => '500']]], 201)['id'];
        $this->call('DELETE', "/api/ui/stock/invoices/$nf2");
        self::assertSame(1, array_column($this->call('GET', '/api/ui/stock/items')['items'], null, 'id')[$monitor]['balance']);
    }

    public function testViewerReadsButCannotWrite(): void
    {
        $this->loginAs($this->client, 'viewer');
        $this->call('GET', '/api/ui/stock/summary');
        $this->call('GET', '/api/ui/stock/invoices');
        $this->call('POST', '/api/ui/stock/items', ['name' => 'X', 'category' => 'Y'], 403);
        $this->call('POST', '/api/ui/stock/out', ['branch_id' => $this->branch, 'lines' => []], 403);
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
