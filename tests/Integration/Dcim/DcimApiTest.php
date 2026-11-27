<?php

declare(strict_types=1);

namespace App\Tests\Integration\Dcim;

use App\Tests\Integration\Support\LogsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-0016: local → rack → equipamentos (portas instanciadas do tipo) → cabos → trace ponta a ponta
 * (estação → tomada → patch panel → switch; tronco de fibra multiposição; circuito até a nuvem da operadora)
 * e IPAM (prefixo, faixa, próximo IP livre, IP primário).
 */
final class DcimApiTest extends WebTestCase
{
    use LogsIn;

    private KernelBrowser $client;
    private Connection $db;
    private int $branch;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $this->db = $db;
        $this->branch = (int) $db->fetchOne("INSERT INTO branch (name, kind, networks, active, created_at, updated_at) VALUES ('Filial DCIM', 'branch', '[]', true, now(), now()) RETURNING id");
        $this->loginAs($this->client, 'editor');
    }

    public function testDeskToSwitchTrace(): void
    {
        $building = $this->id('POST', '/api/ui/dcim/locations', ['name' => 'Prédio', 'kind' => 'building', 'branch_id' => $this->branch]);
        $cpd = $this->id('POST', '/api/ui/dcim/locations', ['name' => 'CPD', 'kind' => 'datacenter', 'parent_id' => $building]);
        $desk = $this->id('POST', '/api/ui/dcim/locations', ['name' => 'Mesa 7', 'kind' => 'workstation', 'parent_id' => $building]);
        $rack = $this->id('POST', '/api/ui/dcim/racks', ['name' => 'R1', 'branch_id' => $this->branch, 'location_id' => $cpd, 'u_height' => 10]);

        $sw = $this->id('POST', '/api/ui/dcim/devices', ['name' => 'SW1', 'device_type_id' => $this->type('Switch 24 portas + 4 SFP+'), 'rack_id' => $rack, 'position' => 5, 'face' => 'front']);
        $pp = $this->id('POST', '/api/ui/dcim/devices', ['name' => 'PP1', 'device_type_id' => $this->type('Patch panel 24 portas Cat6'), 'rack_id' => $rack, 'position' => 6, 'face' => 'front']);
        $this->call('POST', '/api/ui/dcim/devices', ['name' => 'PP2', 'device_type_id' => $this->type('Patch panel 24 portas Cat6'), 'rack_id' => $rack, 'position' => 6, 'face' => 'front'], 422);
        $jack = $this->id('POST', '/api/ui/dcim/devices', ['name' => 'PT-07', 'device_type_id' => $this->type('Ponto de rede simples (RJ45)'), 'branch_id' => $this->branch, 'location_id' => $desk]);
        $pc = $this->id('POST', '/api/ui/dcim/devices', ['name' => 'PC-07', 'device_type_id' => $this->type('Estação de trabalho'), 'branch_id' => $this->branch, 'location_id' => $desk]);
        self::assertSame(30, (int) $this->db->fetchOne('SELECT count(*) FROM dcim_port WHERE device_id = ?', [$sw]));

        $this->cable($this->port($pc, 'interface', 'eth0'), $this->port($jack, 'front', '1'), ['label' => 'C1', 'length' => '2']);
        $this->cable($this->port($jack, 'rear', '1'), $this->port($pp, 'rear', '7'), ['label' => 'H7', 'length' => '30']);
        $this->cable($this->port($pp, 'front', '7'), $this->port($sw, 'interface', '7'), ['label' => 'P7', 'length' => '50', 'length_unit' => 'cm']);
        // porta já cabeada e combinação incompatível
        $this->call('POST', '/api/ui/dcim/cables', ['a_terminations' => [['type' => 'port', 'id' => $this->port($sw, 'interface', '7')]],
            'b_terminations' => [['type' => 'port', 'id' => $this->port($sw, 'interface', '8')]]], 422);
        $this->call('POST', '/api/ui/dcim/cables', ['a_terminations' => [['type' => 'port', 'id' => $this->port($sw, 'power_port', 'Fonte')]],
            'b_terminations' => [['type' => 'port', 'id' => $this->port($sw, 'interface', '8')]]], 422);

        $trace = $this->call('GET', '/api/ui/dcim/ports/'.$this->port($pc, 'interface', 'eth0').'/trace');
        self::assertTrue($trace['complete']);
        self::assertSame(3, $trace['cables']);
        self::assertSame('SW1', self::at($trace, 'ends', 1, 0, 'device'));
        self::assertSame('7', self::at($trace, 'ends', 1, 0, 'name'));
        self::assertEqualsWithDelta(32.5, $trace['length_m'], 0.001);

        // pela tomada, os dois sentidos
        $both = $this->call('GET', '/api/ui/dcim/ports/'.$this->port($jack, 'front', '1').'/trace');
        self::assertTrue($both['bidirectional']);
        self::assertSame('SW1', self::at($both, 'ends', 0, 0, 'device'));
        self::assertSame('PC-07', self::at($both, 'ends', 1, 0, 'device'));

        // tabela de portas do switch: peer e destino
        $detail = $this->call('GET', "/api/ui/dcim/devices/$sw");
        $ports = \is_array($detail['ports']) ? $detail['ports'] : [];
        $p7 = array_values(array_filter($ports, static fn (mixed $p): bool => \is_array($p) && 'interface' === $p['kind'] && '7' === $p['name']))[0] ?? [];
        self::assertSame('PP1', self::at($p7, 'link_peers', 0, 'device'));
        self::assertSame('PC-07', self::at($p7, 'destination', 'nodes', 0, 'device'));

        // excluir a tomada remove os cabos dela
        $this->call('DELETE', "/api/ui/dcim/devices/$jack");
        $after = $this->call('GET', '/api/ui/dcim/ports/'.$this->port($pc, 'interface', 'eth0').'/trace');
        self::assertFalse($after['complete']);
        self::assertSame(0, $after['cables']);
        $this->call('DELETE', "/api/ui/dcim/racks/$rack", null, 422);
    }

    public function testFiberTrunkAndCircuit(): void
    {
        $dioA = $this->id('POST', '/api/ui/dcim/devices', ['name' => 'DIO-A', 'device_type_id' => $this->type('DIO 12 fibras LC'), 'branch_id' => $this->branch]);
        $dioB = $this->id('POST', '/api/ui/dcim/devices', ['name' => 'DIO-B', 'device_type_id' => $this->type('DIO 12 fibras LC'), 'branch_id' => $this->branch]);
        $swA = $this->id('POST', '/api/ui/dcim/devices', ['name' => 'SW-A', 'device_type_id' => $this->type('Switch 24 portas + 4 SFP+'), 'branch_id' => $this->branch]);
        $swB = $this->id('POST', '/api/ui/dcim/devices', ['name' => 'SW-B', 'device_type_id' => $this->type('Switch 24 portas + 4 SFP+'), 'branch_id' => $this->branch]);
        $this->cable($this->port($dioA, 'rear', 'Bandeja'), $this->port($dioB, 'rear', 'Bandeja'), ['type' => 'smf-os2']);
        $this->cable($this->port($swA, 'interface', 'SFP2'), $this->port($dioA, 'front', '5'), []);
        $this->cable($this->port($dioB, 'front', '5'), $this->port($swB, 'interface', 'SFP2'), ['status' => 'planned']);
        $t = $this->call('GET', '/api/ui/dcim/ports/'.$this->port($swA, 'interface', 'SFP2').'/trace');
        self::assertTrue($t['complete']);
        self::assertFalse($t['active']); // um cabo planejado
        self::assertSame('SW-B', self::at($t, 'ends', 1, 0, 'device'));

        $supplier = (int) $this->db->fetchOne("INSERT INTO supplier (name, active, created_at, updated_at) VALUES ('Operadora X', true, now(), now()) RETURNING id");
        $type = (int) $this->db->fetchOne("SELECT id FROM circuit_type WHERE name = 'Internet dedicada'");
        $circuit = $this->id('POST', '/api/ui/circuits', ['supplier_id' => $supplier, 'type_id' => $type, 'cid' => 'X-1']);
        $this->call('PUT', "/api/ui/circuits/$circuit/terminations/A", ['provider_network' => 'Backbone X']);
        $z = (int) $this->call('PUT', "/api/ui/circuits/$circuit/terminations/Z", ['branch_id' => $this->branch])['id'];
        $this->call('POST', '/api/ui/dcim/cables', ['a_terminations' => [['type' => 'circuit', 'id' => $z]],
            'b_terminations' => [['type' => 'port', 'id' => $this->port($swA, 'interface', '1')]]], 201);
        $c = $this->call('GET', '/api/ui/dcim/ports/'.$this->port($swA, 'interface', '1').'/trace');
        self::assertTrue($c['complete']);
        self::assertSame('Backbone X', self::at($c, 'ends', 1, 0, 'provider_network'));
    }

    public function testIpam(): void
    {
        $vrf = $this->id('POST', '/api/ui/ipam/vrfs', ['name' => 'TESTE']);
        $prefix = $this->id('POST', '/api/ui/ipam/prefixes', ['prefix' => '172.30.5.9/24', 'vrf_id' => $vrf, 'branch_id' => $this->branch]);
        $this->call('POST', '/api/ui/ipam/prefixes', ['prefix' => '172.30.5.0/24', 'vrf_id' => $vrf], 422);
        $this->call('POST', '/api/ui/ipam/ranges', ['start_address' => '172.30.5.1', 'end_address' => '172.30.5.10', 'vrf_id' => $vrf], 201);
        self::assertSame('172.30.5.11/24', $this->call('GET', "/api/ui/ipam/prefixes/$prefix/next-ip")['address']);

        $dev = $this->id('POST', '/api/ui/dcim/devices', ['name' => 'FW-T', 'device_type_id' => $this->type('Switch 24 portas + 4 SFP+'), 'branch_id' => $this->branch]);
        $ip = $this->id('POST', '/api/ui/ipam/ip-addresses', ['address' => '172.30.5.11', 'vrf_id' => $vrf, 'port_id' => $this->port($dev, 'interface', '1'), 'make_primary' => true]);
        self::assertSame('172.30.5.11/24', $this->call('GET', "/api/ui/ipam/ip-addresses/$ip")['address']);
        self::assertSame($ip, (int) $this->db->fetchOne('SELECT primary_ip_id FROM dcim_device WHERE id = ?', [$dev]));
        $this->call('POST', '/api/ui/ipam/ip-addresses', ['address' => '172.30.5.11/24', 'vrf_id' => $vrf], 422);
        $this->call('POST', '/api/ui/ipam/ip-addresses', ['address' => '172.30.5.255/24', 'vrf_id' => $vrf], 422);
        self::assertSame('172.30.5.12/24', $this->call('GET', "/api/ui/ipam/prefixes/$prefix/next-ip")['address']);
        $detail = $this->call('GET', "/api/ui/ipam/prefixes/$prefix");
        self::assertEqualsWithDelta(round(100 * 11 / 254, 1), $detail['utilization'], 0.01);
    }

    public function testViewerCannotWrite(): void
    {
        $this->loginAs($this->client, 'viewer');
        $this->call('GET', '/api/ui/dcim/devices');
        $this->call('POST', '/api/ui/dcim/locations', ['name' => 'X', 'branch_id' => $this->branch], 403);
        $this->call('POST', '/api/ui/ipam/prefixes', ['prefix' => '10.0.0.0/8'], 403);
    }

    private function type(string $model): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM dcim_device_type WHERE model = ?', [$model]);
    }

    private function port(int $device, string $kind, string $name): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM dcim_port WHERE device_id = ? AND kind = ? AND name = ?', [$device, $kind, $name]);
    }

    /** @param array<string, mixed> $attrs */
    private function cable(int $a, int $b, array $attrs): void
    {
        $this->call('POST', '/api/ui/dcim/cables', $attrs + ['a_terminations' => [['type' => 'port', 'id' => $a]], 'b_terminations' => [['type' => 'port', 'id' => $b]]], 201);
    }

    /** @param array<string, mixed> $body */
    private function id(string $method, string $uri, array $body): int
    {
        $id = $this->call($method, $uri, $body, 201)['id'] ?? null;

        return \is_int($id) ? $id : (int) (\is_scalar($id) ? $id : 0);
    }

    private static function at(mixed $data, int|string ...$keys): mixed
    {
        foreach ($keys as $k) {
            if (!\is_array($data) || !\array_key_exists($k, $data)) {
                return null;
            }
            $data = $data[$k];
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, ?array $body = null, int $status = 200): array
    {
        $this->client->request($method, $uri, server: self::UI + ['CONTENT_TYPE' => 'application/json'], content: null === $body ? null : (string) json_encode($body));
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        $c = (string) $this->client->getResponse()->getContent();

        /** @var array<string, mixed> */
        return '' === $c ? [] : (array) json_decode($c, true);
    }
}
