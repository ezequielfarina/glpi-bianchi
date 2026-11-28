<?php

declare(strict_types=1);

namespace App\Tests\Integration\Virtualization;

use App\Tests\Integration\Support\LogsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR-0017: cluster → host físico (capacidade) → VMs (interfaces, discos, IP primário)
 * → caminho reverso até a internet e grafo do mapa da rede.
 */
final class VirtualizationApiTest extends WebTestCase
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
        $this->branch = (int) $db->fetchOne("INSERT INTO branch (name, kind, networks, active, created_at, updated_at) VALUES ('Filial Virt', 'branch', '[]', true, now(), now()) RETURNING id");
        $this->loginAs($this->client, 'editor');
    }

    public function testClusterVmAndPathToInternet(): void
    {
        $pve = (int) $this->db->fetchOne("SELECT id FROM virt_cluster_type WHERE name = 'Proxmox VE'");
        self::assertGreaterThan(0, $pve);
        $this->call('POST', '/api/ui/virtualization/clusters', ['name' => 'C', 'type_id' => $pve, 'management_url' => 'pve:8006'], 422);
        $cluster = $this->id('POST', '/api/ui/virtualization/clusters', ['name' => 'PVE-T', 'type_id' => $pve, 'branch_id' => $this->branch, 'management_url' => 'https://pve:8006']);

        $hostRole = (int) $this->db->fetchOne("SELECT id FROM dcim_device_role WHERE name = 'Host de virtualização'");
        $host = $this->id('POST', '/api/ui/dcim/devices', ['name' => 'PVE-T1', 'device_type_id' => $this->type('Estação de trabalho'), 'role_id' => $hostRole,
            'branch_id' => $this->branch, 'cluster_id' => $cluster, 'cpu_threads' => 32, 'memory_mb' => 65536, 'storage_gb' => 2000]);
        $other = $this->id('POST', '/api/ui/dcim/devices', ['name' => 'SW-T', 'device_type_id' => $this->type('Switch 24 portas + 4 SFP+'), 'branch_id' => $this->branch]);

        $vm = $this->id('POST', '/api/ui/virtualization/vms', ['name' => 'VM-1', 'cluster_id' => $cluster, 'device_id' => $host, 'vcpus' => '2,5', 'memory_mb' => 4096]);
        $this->call('POST', '/api/ui/virtualization/vms', ['name' => 'VM-1', 'cluster_id' => $cluster], 422);
        $this->call('POST', '/api/ui/virtualization/vms', ['name' => 'VM-2', 'cluster_id' => $cluster, 'device_id' => $other], 422);

        $this->call('POST', "/api/ui/virtualization/vms/$vm/disks", ['name' => 'scsi0', 'size_gb' => 40], 201);
        $this->call('POST', "/api/ui/virtualization/vms/$vm/disks", ['name' => 'scsi1', 'size_gb' => 60], 201);
        $ids = $this->call('POST', "/api/ui/virtualization/vms/$vm/interfaces", ['name' => 'net0', 'bridge' => 'vmbr0'], 201)['ids'] ?? [];
        $iface = \is_array($ids) && isset($ids[0]) && is_numeric($ids[0]) ? (int) $ids[0] : 0;
        $this->id('POST', '/api/ui/ipam/ip-addresses', ['address' => '10.99.0.10/24', 'vm_interface_id' => $iface, 'make_primary' => true]);
        $this->call('POST', '/api/ui/ipam/ip-addresses', ['address' => '10.99.0.11/24', 'vm_interface_id' => $iface, 'port_id' => $this->port($host, 'interface', 'eth0')], 422);

        $detail = $this->call('GET', "/api/ui/virtualization/vms/$vm");
        self::assertSame(100, $detail['disk_gb']);
        self::assertEqualsWithDelta(2.5, $detail['vcpus'], 0.001);
        self::assertSame('10.99.0.10/24', $detail['primary_ip']);

        $dev = $this->call('GET', "/api/ui/dcim/devices/$host");
        self::assertSame(32, self::at($dev, 'capacity', 'cpu_threads'));
        self::assertSame('VM-1', self::at($dev, 'vms', 0, 'name'));

        // sem cabo: caminho não encontrado
        self::assertSame('no_cable', $this->call('GET', "/api/ui/dcim/devices/$host/path-to-internet")['reason']);

        // host → switch → circuito da operadora
        $this->cable($this->port($host, 'interface', 'eth0'), $this->port($other, 'interface', '1'));
        $supplier = (int) $this->db->fetchOne("INSERT INTO supplier (name, active, created_at, updated_at) VALUES ('Operadora V', true, now(), now()) RETURNING id");
        $ctype = (int) $this->db->fetchOne("SELECT id FROM circuit_type WHERE name = 'Internet dedicada'");
        $circuit = $this->id('POST', '/api/ui/circuits', ['supplier_id' => $supplier, 'type_id' => $ctype, 'cid' => 'V-1']);
        $this->call('PUT', "/api/ui/circuits/$circuit/terminations/A", ['provider_network' => 'Backbone V']);
        $z = (int) $this->call('PUT', "/api/ui/circuits/$circuit/terminations/Z", ['branch_id' => $this->branch])['id'];
        $this->call('POST', '/api/ui/dcim/cables', ['a_terminations' => [['type' => 'circuit', 'id' => $z]],
            'b_terminations' => [['type' => 'port', 'id' => $this->port($other, 'interface', '24')]]], 201);

        $path = $this->call('GET', "/api/ui/dcim/devices/$host/path-to-internet");
        self::assertTrue($path['found']);
        self::assertSame('SW-T', self::at($path, 'segments', 0, 'to_device'));
        self::assertSame('circuit', self::at($path, 'segments', 1, 'to_kind'));
        self::assertSame('V-1', self::at($path, 'segments', 1, 'cid'));

        $graph = $this->call('GET', "/api/ui/dcim/topology?branch={$this->branch}&mode=logical&vms=1");
        $nodes = \is_array($graph['nodes']) ? $graph['nodes'] : [];
        $kinds = array_map(static fn (mixed $n): mixed => \is_array($n) ? $n['kind'] : null, $nodes);
        self::assertContains('vm', $kinds);
        self::assertContains('cluster', $kinds);
        self::assertContains('circuit', $kinds);

        // cluster com VM não pode ser excluído; tirar o host do cluster solta as VMs dele
        $this->call('DELETE', "/api/ui/virtualization/clusters/$cluster", null, 422);
        $this->call('POST', "/api/ui/virtualization/clusters/$cluster/hosts", ['device_id' => $host, 'member' => false]);
        self::assertNull($this->db->fetchOne('SELECT device_id FROM virt_machine WHERE id = ?', [$vm]));
    }

    public function testEditablePlatformListAndRoleIcon(): void
    {
        $pve = (int) $this->db->fetchOne("SELECT id FROM virt_cluster_type WHERE name = 'Proxmox VE'");
        $cluster = $this->id('POST', '/api/ui/virtualization/clusters', ['name' => 'PVE-P', 'type_id' => $pve]);
        $os = $this->id('POST', '/api/ui/dcim/platforms', ['name' => 'Alpine 3.20']);
        $this->call('POST', '/api/ui/dcim/platforms', ['name' => 'alpine 3.20'], 422);
        $vm = $this->id('POST', '/api/ui/virtualization/vms', ['name' => 'VM-P', 'cluster_id' => $cluster, 'platform' => 'Alpine 3.20']);

        // renomear na lista renomeia nas VMs; excluir em uso é recusado
        $this->call('PUT', "/api/ui/dcim/platforms/$os", ['name' => 'Alpine Linux 3.20']);
        self::assertSame('Alpine Linux 3.20', $this->db->fetchOne('SELECT platform FROM virt_machine WHERE id = ?', [$vm]));
        $this->call('DELETE', "/api/ui/dcim/platforms/$os", null, 422);
        $this->call('DELETE', "/api/ui/virtualization/vms/$vm");
        $this->call('DELETE', "/api/ui/dcim/platforms/$os");

        // função: desenho do mapa validado; renomear não apaga cor/desenho
        $this->call('POST', '/api/ui/dcim/roles', ['name' => 'Tanque', 'icon' => 'tank'], 422);
        $role = $this->id('POST', '/api/ui/dcim/roles', ['name' => 'Câmera IP', 'color' => '78909c', 'icon' => 'camera']);
        $this->call('PUT', "/api/ui/dcim/roles/$role", ['name' => 'Câmera']);
        self::assertSame(['camera', '78909c'], array_values((array) $this->db->fetchAssociative('SELECT icon, color FROM dcim_device_role WHERE id = ?', [$role])));
        $vmRole = (int) $this->db->fetchOne("SELECT id FROM dcim_device_role WHERE name = 'Máquina virtual'");
        $this->id('POST', '/api/ui/virtualization/vms', ['name' => 'VM-R', 'cluster_id' => $cluster]);
        $this->call('DELETE', "/api/ui/dcim/roles/$vmRole", null, 422);
    }

    public function testViewerCannotWrite(): void
    {
        $this->loginAs($this->client, 'viewer');
        $this->call('GET', '/api/ui/virtualization/vms');
        $this->call('POST', '/api/ui/virtualization/clusters', ['name' => 'X'], 403);
    }

    private function type(string $model): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM dcim_device_type WHERE model = ?', [$model]);
    }

    private function port(int $device, string $kind, string $name): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM dcim_port WHERE device_id = ? AND kind = ? AND name = ?', [$device, $kind, $name]);
    }

    private function cable(int $a, int $b): void
    {
        $this->call('POST', '/api/ui/dcim/cables', ['a_terminations' => [['type' => 'port', 'id' => $a]], 'b_terminations' => [['type' => 'port', 'id' => $b]]], 201);
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
