<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Asset\Entity\Asset;
use App\Asset\Entity\AssetHistory;
use App\Asset\Enum\ComponentKind;
use App\Inventory\Entity\Agent;
use App\Inventory\Entity\RawInventory;
use App\Inventory\Enum\RawInventoryStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fluxo ponta a ponta do protocolo do agente, com o transporte "sync" do
 * Messenger (APP_ENV=test): POST → RawInventory → importador → Asset.
 *
 * Requer o banco de teste migrado: `make test-db`.
 */
final class AgentEndpointTest extends WebTestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/inventory/computer-linux.json';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testContactAnnouncesOnlyInventoryTask(): void
    {
        $response = $this->post([
            'action' => 'contact',
            'deviceid' => 'pc-001-2026-11-26-10-00-00',
            'enabled-tasks' => ['inventory', 'netdiscovery'],
        ]);

        self::assertSame('ok', $response['status']);
        self::assertSame(24, $response['expiration']);
        self::assertSame('glpi-bianchi', $response['tasks']['inventory']['server']);
        self::assertSame('netdiscovery', $response['disabled']);
        self::assertArrayNotHasKey('netdiscovery', $response['tasks']);

        $agent = $this->em()->getRepository(Agent::class)->findOneBy(['deviceId' => 'pc-001-2026-11-26-10-00-00']);
        self::assertNotNull($agent);
        self::assertSame('pc-001', $agent->getName());
    }

    public function testLegacyXmlPrologGetsXmlReply(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><REQUEST><DEVICEID>old-pc-2026-01-01-00-00-00</DEVICEID><QUERY>PROLOG</QUERY></REQUEST>';
        $this->client->request('POST', '/front/inventory.php', server: ['CONTENT_TYPE' => 'application/xml'], content: $xml);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<PROLOG_FREQ>24</PROLOG_FREQ>', $body);
        self::assertStringContainsString('<RESPONSE>SEND</RESPONSE>', $body);
    }

    public function testFullInventoryCreatesAssetWithComponentsSoftwareAndNetwork(): void
    {
        $response = $this->post($this->fixture());
        self::assertSame('ok', $response['status']);

        $asset = $this->asset();
        self::assertSame('srv-lab01', $asset->getName());
        self::assertSame('4c4c4544-0042-3510-8051-b4c04f4a3132', $asset->getHardwareUuid());
        self::assertSame('Dell Inc.', $asset->getManufacturer()?->getName());
        self::assertSame('PowerEdge R640', $asset->getModel()?->getName());
        self::assertSame('Debian GNU/Linux 13 (trixie)', $asset->getOperatingSystem()->getFullName());
        self::assertSame(16384, $asset->getAttribute('memory_mib'));
        self::assertTrue($asset->isDynamic());

        self::assertSame(1, $this->countComponents($asset, ComponentKind::Processor));
        self::assertSame(2, $this->countComponents($asset, ComponentKind::Memory), 'slot vazio deve ser ignorado');
        self::assertSame(1, $this->countComponents($asset, ComponentKind::Storage));
        self::assertSame(1, $this->countComponents($asset, ComponentKind::Drive));
        self::assertCount(3, $asset->getSoftwareInstallations());

        self::assertCount(1, $asset->getNetworkPorts(), 'loopback descartado; IPv4+IPv6 agrupados na mesma porta');
        self::assertCount(2, $asset->getNetworkPorts()->first()->getIpAddresses());
        self::assertSame('b0:26:28:aa:bb:01', $asset->getNetworkPorts()->first()->getMacAddress(), 'campo JSON "mac"');

        $raw = $this->em()->getRepository(RawInventory::class)->findOneBy(['deviceId' => 'srv-lab01-2026-11-26-10-00-00']);
        self::assertSame(RawInventoryStatus::Processed, $raw?->getStatus());
    }

    public function testReinventoryReconcilesAndRecordsHistory(): void
    {
        $this->post($this->fixture());

        $payload = $this->fixture();
        array_pop($payload['content']['memories']);      // remove slot vazio (sem efeito)
        array_pop($payload['content']['memories']);      // remove 1 pente
        $payload['content']['softwares'][] = ['name' => 'zabbix-agent2', 'version' => '7.4.2', 'publisher' => 'Zabbix SIA', 'arch' => 'amd64'];
        $payload['content']['operatingsystem']['kernel_version'] = '6.12.57-1';
        $this->post($payload);

        $asset = $this->asset();
        self::assertSame(1, $this->countComponents($asset, ComponentKind::Memory));
        self::assertCount(4, $asset->getSoftwareInstallations());

        $events = array_map(
            static fn (AssetHistory $h): string => $h->getField(),
            $this->em()->getRepository(AssetHistory::class)->findBy(['asset' => $asset]),
        );
        self::assertContains('component.removed', $events);
        self::assertContains('software.installed', $events);
        self::assertContains('operatingSystem', $events);
        self::assertCount(1, $this->em()->getRepository(Asset::class)->findAll(), 'mesmo UUID → mesmo ativo');
    }

    public function testPartialInventoryKeepsMissingSections(): void
    {
        $this->post($this->fixture());

        $partial = $this->fixture();
        $partial['partial'] = true;
        $hardware = $partial['content']['hardware'];
        unset($hardware['lastloggeduser']); // agente rodando como SYSTEM, sem sessão
        $partial['content'] = [
            'versionclient' => $partial['content']['versionclient'], // obrigatório no schema
            'hardware' => $hardware,
            'softwares' => [],
        ];
        $this->post($partial);

        $asset = $this->asset();
        self::assertSame(1, $this->countComponents($asset, ComponentKind::Processor), 'cpus ausente em parcial → mantém');
        self::assertCount(0, $asset->getSoftwareInstallations(), 'softwares presente e vazio → remove dinâmicos');
        self::assertSame('ezequiel.farina', $asset->getAttribute('last_logged_user'), 'campo ausente no parcial → mantém');
    }

    public function testDeletedAssetIsRestoredWhenAgentReportsAgain(): void
    {
        $this->post($this->fixture());
        $asset = $this->asset();
        $uuid = (string) $asset->getUuid();
        $number = $asset->getInventoryNumber();

        $this->client->request('DELETE', '/api/ui/assets/'.$uuid, server: ['HTTP_AUTHORIZATION' => 'Bearer test-token', 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        $this->post($this->fixture());
        $this->em()->clear();
        $all = $this->em()->getRepository(Asset::class)->findAll();
        self::assertCount(1, $all, 'sem duplicar: o mesmo ativo volta da lixeira');
        self::assertSame($uuid, (string) $all[0]->getUuid());
        self::assertFalse($all[0]->isDeleted());
        self::assertSame($number, $all[0]->getInventoryNumber(), 'mantém o patrimônio');
        $events = array_map(static fn (AssetHistory $h): string => $h->getField(), $this->em()->getRepository(AssetHistory::class)->findBy(['asset' => $all[0]]));
        self::assertContains('asset.restored', $events);
    }

    public function testAgentAssetGetsBranchByNetwork(): void
    {
        $this->client->request('POST', '/api/ui/branches', server: ['HTTP_AUTHORIZATION' => 'Bearer test-token', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Filial Teste', 'networks' => '192.168.10.0/24'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->post($this->fixture()); // eno1 = 192.168.10.50
        self::assertSame('Filial Teste', $this->asset()->getBranch()?->getName());
    }

    public function testBrowserOnRootIsRedirectedToWebInterface(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/app/', 302);
    }

    public function testInvalidJsonReturns400(): void
    {
        $this->client->request('POST', '/', server: ['CONTENT_TYPE' => 'application/json'], content: '{nope');
        self::assertResponseStatusCodeSame(400);
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function post(array $payload): array
    {
        $this->client->request(
            'POST',
            '/',
            server: ['CONTENT_TYPE' => 'application/x-compress-zlib', 'HTTP_GLPI_AGENT_ID' => 'a3a1e9c4-0000-4000-8000-000000000001'],
            content: (string) gzcompress(json_encode($payload, \JSON_THROW_ON_ERROR)),
        );
        self::assertResponseIsSuccessful((string) @gzuncompress((string) $this->client->getResponse()->getContent()));

        /** @var array<string, mixed> */
        return json_decode((string) gzuncompress((string) $this->client->getResponse()->getContent()), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents(self::FIXTURE), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function asset(): Asset
    {
        $this->em()->clear();
        $asset = $this->em()->getRepository(Asset::class)->findOneBy(['serialNumber' => 'BX5Q2J2']);
        self::assertNotNull($asset);

        return $asset;
    }

    private function countComponents(Asset $asset, ComponentKind $kind): int
    {
        return $asset->getComponents()->filter(static fn ($c) => $c->getKind() === $kind)->count();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
