<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ui;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Gestão via /api/ui: ativo manual com patrimônio e foto, fornecedor/contato,
 * contrato com ativo, licença com chave cifrada e controle de assentos, cadastros.
 * (Os mesmos cenários foram validados contra PostgreSQL no desenvolvimento — ADR-0009.)
 */
final class ManageApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testManualAssetWithInventoryNumberPhotoAndHistory(): void
    {
        $next = $this->call('GET', '/api/ui/assets/next-inventory-number')['next'];
        self::assertMatchesRegularExpression('/^BIA-\d{6}$/', $next);

        $uuid = $this->call('POST', '/api/ui/assets', ['type' => 'printer', 'name' => 'IMP-RH-01', 'manufacturer' => 'Epson', 'attributes' => ['IP' => '10.0.0.9']], 201)['uuid'];
        $asset = $this->call('GET', '/api/ui/assets/'.$uuid);
        self::assertSame($next, $asset['inventory_number']);
        self::assertSame('10.0.0.9', $asset['attributes']['ip']);

        $this->call('POST', '/api/ui/assets', ['type' => 'tablet', 'name' => 'X', 'inventory_number' => $next], 422);

        $png = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $this->client->request('POST', '/api/ui/attachments', ['owner_type' => 'asset', 'owner' => $uuid, 'kind' => 'photo'],
            ['file' => new UploadedFile($png, 'foto.png', 'image/png', null, true)], $this->auth());
        self::assertResponseStatusCodeSame(201);
        $photo = $this->call('GET', '/api/ui/assets/'.$uuid)['photo_uuid'];
        self::assertNotNull($photo);

        $this->client->request('GET', '/api/ui/attachments/'.$photo, server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));

        $this->call('PATCH', '/api/ui/assets/'.$uuid, ['name' => 'IMP-RH-01A', 'status' => 'in_repair']);
        $fields = array_column($this->call('GET', '/api/ui/assets/'.$uuid)['history'], 'field');
        self::assertContains('name', $fields);
        self::assertContains('photo.added', $fields);
    }

    public function testSupplierContractLicenseFlow(): void
    {
        $lookups = $this->call('GET', '/api/ui/lookups');
        $m365 = array_values(array_filter($lookups['license_types'], static fn (array $t): bool => 'user' === $t['assignment']))[0]['id'];

        $supplier = $this->call('POST', '/api/ui/suppliers', ['name' => 'Fornecedor X', 'tax_id' => '11.222.333/0001-81'], 201)['id'];
        $this->call('POST', '/api/ui/suppliers', ['name' => 'Dup', 'tax_id' => '11222333000181'], 422);
        $this->call('POST', "/api/ui/suppliers/$supplier/contacts", ['name' => 'Ana', 'email' => 'ana@x.com'], 201);

        $contract = $this->call('POST', '/api/ui/contracts', ['title' => 'Locação', 'supplier_id' => $supplier, 'value' => '1.000,50', 'billing_period' => 'monthly', 'end_date' => date('Y-m-d', strtotime('+10 days'))], 201)['id'];
        $c = $this->call('GET', "/api/ui/contracts/$contract");
        self::assertSame('expiring', $c['status']);
        self::assertSame('12006.00', $c['annual_value']);

        $license = $this->call('POST', '/api/ui/licenses', ['name' => 'M365 Business', 'license_type_id' => $m365, 'seats' => 1, 'license_key' => 'AAAAA-BBBBB', 'supplier_id' => $supplier], 201)['id'];
        self::assertSame('AAAAA-BBBBB', $this->call('GET', "/api/ui/licenses/$license/key")['key']);
        $this->call('POST', "/api/ui/licenses/$license/assignments", ['user' => 'ana@x.com'], 201);
        $this->call('POST', "/api/ui/licenses/$license/assignments", ['user' => 'bob'], 422);       // sem assento
        $this->call('POST', "/api/ui/licenses/$license/assignments", ['user' => 'bob', 'force' => true], 201);
        $l = $this->call('GET', "/api/ui/licenses/$license");
        self::assertSame('over', $l['compliance']);
        self::assertCount(2, $l['assignments']);

        $s = $this->call('GET', "/api/ui/suppliers/$supplier");
        self::assertCount(1, $s['contacts_list']);
        self::assertCount(1, $s['contracts_list']);
        self::assertSame(1, $this->call('GET', '/api/ui/alerts')['licenses_over']);
    }

    /** @param array<string, mixed>|null $body @return array<string, mixed> */
    private function call(string $method, string $uri, ?array $body = null, int $expect = 200): array
    {
        $this->client->request($method, $uri, server: $this->auth() + ['CONTENT_TYPE' => 'application/json'], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($expect, (string) $this->client->getResponse()->getContent());

        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer test-token', 'HTTP_ACCEPT' => 'application/json'];
    }
}
