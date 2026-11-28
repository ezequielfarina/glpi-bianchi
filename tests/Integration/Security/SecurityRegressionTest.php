<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Attachment\Service\AttachmentStorage;
use App\Shared\Security\FileCipher;
use App\Tests\Integration\Support\LogsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Regressão dos achados da auditoria de segurança 2026-11 (docs/security/auditoria-2026-11.md).
 */
final class SecurityRegressionTest extends WebTestCase
{
    use LogsIn;

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $this->db = $db;
    }

    /** M1: 5 senhas erradas de um IP não travam a conta para quem está em outro IP. */
    public function testFailuresFromOneIpDoNotLockTheAccountElsewhere(): void
    {
        self::createAccount('vitima', 'admin');
        for ($i = 0; $i < 5; ++$i) {
            $this->loginFrom('203.0.113.9', 'vitima', 'chute '.$i, 401);
        }
        $this->loginFrom('203.0.113.9', 'vitima', self::TEST_PASSWORD, 429);
        $this->loginFrom('192.168.10.20', 'vitima', self::TEST_PASSWORD, 200);
    }

    /** L1: senha vazia não derruba o servidor (warning do libsodium virava 500). */
    public function testEmptyCurrentPasswordIsValidationError(): void
    {
        $this->loginAs($this->client, 'viewer');
        $this->send('POST', '/api/ui/me/password', ['current_password' => '', 'new_password' => 'outra frase longa 42'], 422);
    }

    /** M3: sessão roubada não adivinha a senha atual sem limite. */
    public function testChangePasswordIsThrottled(): void
    {
        $this->loginAs($this->client, 'viewer');
        for ($i = 0; $i < 5; ++$i) {
            $this->send('POST', '/api/ui/me/password', ['current_password' => 'errada '.$i, 'new_password' => 'outra frase longa 42'], 422);
        }
        $r = $this->send('POST', '/api/ui/me/password', ['current_password' => self::TEST_PASSWORD, 'new_password' => 'outra frase longa 42'], 422);
        self::assertStringContainsString('Muitas tentativas', (string) json_encode($r, \JSON_UNESCAPED_UNICODE));
    }

    /** H4: link `javascript:` no cadastro vira XSS armazenado — recusado. */
    public function testSupplierWebsiteRejectsScriptUrls(): void
    {
        $this->loginAs($this->client, 'editor');
        foreach (['javascript:alert(document.domain)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>', 'https://x" onmouseover="alert(1)'] as $bad) {
            $this->send('POST', '/api/ui/suppliers', ['name' => 'F '.md5($bad), 'website' => $bad], 422);
        }
        $id = (int) ($this->send('POST', '/api/ui/suppliers', ['name' => 'Fornecedor Bom', 'website' => 'fornecedor.com.br'], 201)['id'] ?? 0);
        self::assertSame('https://fornecedor.com.br', $this->db->fetchOne('SELECT website FROM supplier WHERE id = ?', [$id]));
    }

    /** L2: parâmetro numérico absurdo → 400, não 500. */
    public function testOutOfRangeQueryParameterIsBadRequest(): void
    {
        $this->loginAs($this->client, 'viewer');
        $this->client->request('GET', '/api/ui/assets?branch=99999999999999999999', server: self::UI);
        self::assertResponseStatusCodeSame(400);
    }

    /** ADR-0019: anexo cifrado no disco, download devolve o original. */
    public function testAttachmentIsEncryptedAtRest(): void
    {
        $this->loginAs($this->client, 'editor');
        $supplier = (int) ($this->send('POST', '/api/ui/suppliers', ['name' => 'Fornecedor NF'], 201)['id'] ?? 0);
        $plain = "Nota fiscal 000123 - CNPJ 12.345.678/0001-99 - valor R$ 1.550,00\n";
        $txt = (string) tempnam(sys_get_temp_dir(), 'nf');
        file_put_contents($txt, str_repeat($plain, 50));
        $this->client->request(
            'POST',
            '/api/ui/attachments',
            ['owner_type' => 'supplier', 'owner' => (string) $supplier, 'kind' => 'document'],
            ['file' => new UploadedFile($txt, 'nf.txt', 'text/plain', null, true)],
            self::UI
        );
        self::assertResponseStatusCodeSame(201);
        $uuid = (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['uuid'] ?? '');

        $row = $this->db->fetchAssociative('SELECT storage_path, encryption_key FROM attachment WHERE uuid = ?', [$uuid]);
        self::assertIsArray($row);
        self::assertStringStartsWith('v2.', (string) $row['encryption_key']);
        $storage = self::getContainer()->get(AttachmentStorage::class);
        self::assertInstanceOf(AttachmentStorage::class, $storage);
        $path = (string) ($storage->locate($uuid)['path'] ?? '');
        self::assertTrue(FileCipher::isEncrypted($path));
        self::assertStringNotContainsString('12.345.678', (string) file_get_contents($path));

        $this->client->request('GET', '/api/ui/attachments/'.$uuid, server: self::UI);
        self::assertResponseIsSuccessful();
        self::assertSame(str_repeat($plain, 50), $this->client->getInternalResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /** ADR-0019: payload bruto do agente não fica em claro no banco. */
    public function testRawInventoryPayloadIsSealed(): void
    {
        $this->client->request('POST', '/', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.168.1.50'], content: (string) json_encode([
            'action' => 'inventory', 'deviceid' => 'pc-cofre-2026-11-28-00-00-00', 'itemtype' => 'Computer',
            'content' => ['hardware' => ['name' => 'PC-COFRE'], 'bios' => ['ssn' => 'SERIAL-SIGILOSO-777'], 'versionclient' => 'GLPI-Agent_v1.11'],
        ]));
        $row = $this->db->fetchAssociative("SELECT payload_sealed, payload_legacy FROM raw_inventory WHERE device_id = 'pc-cofre-2026-11-28-00-00-00'");
        self::assertIsArray($row);
        self::assertNull($row['payload_legacy']);
        self::assertStringStartsWith('v2.', (string) $row['payload_sealed']);
        self::assertStringNotContainsString('SIGILOSO', (string) $row['payload_sealed']);
    }

    /** @return array<string, mixed> */
    private function loginFrom(string $ip, string $username, string $password, int $expect): array
    {
        $this->client->request(
            'POST',
            '/api/ui/auth/login',
            server: self::UI + ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip],
            content: (string) json_encode(['username' => $username, 'password' => $password])
        );
        self::assertResponseStatusCodeSame($expect, (string) $this->client->getResponse()->getContent());

        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, array $body, int $expect): array
    {
        $this->client->request($method, $uri, server: self::UI + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
        self::assertResponseStatusCodeSame($expect, (string) $this->client->getResponse()->getContent());

        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
