<?php

declare(strict_types=1);

namespace App\Ipam\Service;

use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;

/**
 * Endereços IP (ADR-0016 §7). Porta de `ipam.IPAddress` (netbox/ipam/models/ip.py):
 *  - endereço com máscara; sem máscara, herda a do menor prefixo que o contém;
 *  - único por VRF quando a VRF (ou a tabela global) exige — sob advisory lock para não haver corrida;
 *  - atribuído a uma interface física ou de VM (ADR-0017); pode virar o IP primário do equipamento/VM;
 *  - rede e broadcast de um prefixo IPv4 comum são recusados (IPAddress.clean).
 */
final readonly class IpAddressService
{
    public const array STATUS = ['active' => 'Ativo', 'reserved' => 'Reservado', 'deprecated' => 'Obsoleto', 'dhcp' => 'DHCP'];
    public const array ROLES = ['loopback' => 'Loopback', 'secondary' => 'Secundário', 'gateway' => 'Gateway', 'vip' => 'VIP', 'vrrp' => 'VRRP',
        'hsrp' => 'HSRP', 'anycast' => 'Anycast'];

    public function __construct(
        private Connection $db,
    ) {
    }

    /**
     * @param array{q?: string|null, status?: string|null, role?: string|null, branch?: int|null, assigned?: bool|null, device?: int|null, vm?: int|null, id?: int|null} $f
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $f = []): array
    {
        $q = $f['q'] ?? null;
        $net = null === $q ? null : IpNet::parse($q);

        return $this->db->fetchAllAssociative(<<<'SQL'
            SELECT i.id, host(i.address) || '/' || masklen(i.address) AS address, family(i.address) AS family, i.status, i.role, i.dns_name, i.description,
                   i.port_id, po.name AS port, d.id AS device_id, d.name AS device, coalesce(b.name, vb.name) AS branch,
                   coalesce(d.primary_ip_id = i.id, vm.primary_ip_id = i.id) AS is_primary,
                   i.vm_interface_id, vi.name AS vm_interface, vm.id AS vm_id, vm.name AS vm,
                   (SELECT x.id FROM ipam_prefix x WHERE x.prefix >>= inet(host(i.address)) ORDER BY masklen(x.prefix) DESC LIMIT 1) AS prefix_id,
                   (SELECT x.prefix::text FROM ipam_prefix x WHERE x.prefix >>= inet(host(i.address)) ORDER BY masklen(x.prefix) DESC LIMIT 1) AS prefix
            FROM ipam_ip_address i
            LEFT JOIN dcim_port po ON po.id = i.port_id
            LEFT JOIN dcim_device d ON d.id = po.device_id
            LEFT JOIN branch b ON b.id = d.branch_id
            LEFT JOIN virt_interface vi ON vi.id = i.vm_interface_id
            LEFT JOIN virt_machine vm ON vm.id = vi.vm_id
            LEFT JOIN virt_cluster vc ON vc.id = vm.cluster_id
            LEFT JOIN branch vb ON vb.id = vc.branch_id
            WHERE (CAST(:id AS int) IS NULL OR i.id = :id)
              AND (CAST(:status AS text) IS NULL OR i.status = :status)
              AND (CAST(:role AS text) IS NULL OR i.role = :role)
              AND (CAST(:branch AS int) IS NULL OR d.branch_id = :branch OR vc.branch_id = :branch)
              AND (CAST(:vm AS int) IS NULL OR vm.id = :vm)
              AND (CAST(:device AS int) IS NULL OR d.id = :device)
              AND (CAST(:assigned AS boolean) IS NULL OR (i.port_id IS NOT NULL OR i.vm_interface_id IS NOT NULL) = CAST(:assigned AS boolean))
              AND (CAST(:q AS text) IS NULL OR host(i.address) LIKE CAST(:q AS text) || '%' OR i.dns_name ILIKE '%' || CAST(:q AS text) || '%'
                   OR i.description ILIKE '%' || CAST(:q AS text) || '%' OR d.name ILIKE '%' || CAST(:q AS text) || '%' OR vm.name ILIKE '%' || CAST(:q AS text) || '%'
                   OR (CAST(:qnet AS cidr) IS NOT NULL AND inet(host(i.address)) <<= CAST(:qnet AS cidr)))
            ORDER BY i.address
            LIMIT 5000
            SQL, ['id' => $f['id'] ?? null, 'status' => $f['status'] ?? null, 'role' => $f['role'] ?? null, 'branch' => $f['branch'] ?? null,
            'device' => $f['device'] ?? null, 'vm' => $f['vm'] ?? null, 'assigned' => null === ($f['assigned'] ?? null) ? null : (($f['assigned'] ?? false) ? 'true' : 'false'),
            'q' => $q, 'qnet' => null !== $net && str_contains((string) $q, '/') ? IpNet::cidr($net['bin'], $net['len']) : null]);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $ip = $this->list(['id' => $id])[0] ?? throw new NotFoundException('Endereço IP não encontrado.');
        $ip['comments'] = $this->db->fetchOne('SELECT comments FROM ipam_ip_address WHERE id = ?', [$id]) ?: null;

        return $ip;
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $text = $p->string('address', 64, required: true);
        $portId = $p->int('port_id', 1);
        $vmIfaceId = $p->int('vm_interface_id', 1);
        if (null !== $portId && null !== $vmIfaceId) {
            $p->error('port_id', 'O IP fica numa interface física OU numa interface de VM, não nas duas.');
        }
        $vmIface = null;
        if (null !== $vmIfaceId) {
            $vmIface = $this->db->fetchAssociative('SELECT id, vm_id FROM virt_interface WHERE id = ?', [$vmIfaceId]) ?: null;
            if (null === $vmIface) {
                $p->error('vm_interface_id', 'Interface da VM não encontrada.');
            }
        }
        $net = null === $text ? null : IpNet::parse($text);
        if (null !== $text && null === $net) {
            $p->error('address', 'Endereço inválido (ex.: 192.168.10.20/24).');
        }
        $port = null;
        if (null !== $portId) {
            $port = $this->db->fetchAssociative('SELECT id, device_id, kind FROM dcim_port WHERE id = ?', [$portId]);
            if (false === $port || 'interface' !== $port['kind']) {
                $p->error('port_id', 'O IP só pode ser atribuído a uma interface de rede.');
                $port = null;
            }
        }
        $dns = $p->string('dns_name', 255);
        if (null !== $dns && 1 !== preg_match('/^([a-z0-9_*]([a-z0-9_-]{0,62})?\.)*[a-z0-9_]([a-z0-9_-]{0,62})?\.?$/i', $dns)) {
            $p->error('dns_name', 'Nome DNS inválido.');
        }
        $p->check();
        \assert(null !== $net && null !== $text);
        $host = IpNet::text($net['bin']);
        $len = str_contains($text, '/') ? $net['len'] : ($this->containingLen($host) ?? $net['bits']);
        $address = $host.'/'.$len;
        // rede/broadcast do prefixo IPv4 comum (IPAddress.clean)
        if (4 === $net['family'] && $len < 31) {
            $network = IpNet::network($net['bin'], $len);
            $isPool = (bool) $this->db->fetchOne('SELECT is_pool FROM ipam_prefix WHERE prefix = CAST(? AS cidr)', [IpNet::cidr($net['bin'], $len)]);
            if (!$isPool && ($net['bin'] === $network || $net['bin'] === IpNet::last($net['bin'], $len))) {
                throw new ValidationException(['address' => $net['bin'] === $network ? 'Esse é o endereço de rede.' : 'Esse é o endereço de broadcast.']);
            }
        }
        $row = ['address' => $address, 'port_id' => $portId, 'vm_interface_id' => $vmIfaceId, 'status' => $p->enum('status', array_keys(self::STATUS), 'active'),
            'role' => $p->enum('role', array_keys(self::ROLES)), 'dns_name' => $dns, 'description' => $p->string('description', 255), 'comments' => $p->string('comments', 10000)];
        $makePrimary = $p->bool('make_primary');
        if ($makePrimary && null === $port && null === $vmIface) {
            $p->error('make_primary', 'Atribua o IP a uma interface para torná-lo primário.');
        }
        $p->check();

        return (int) $this->db->transactional(function () use ($id, $row, $host, $makePrimary, $port, $vmIface): int {
            // IP único na tabela global (sem VRF): trava para dois cadastros simultâneos não duplicarem
            $this->db->executeStatement('SELECT pg_advisory_xact_lock(hashtext(?))', ['ipam_ip:0']);
            $dup = $this->db->fetchOne(<<<'SQL'
                SELECT coalesce(d.name || ' ' || p.name, vm.name || ' ' || vi.name, 'IP #' || i.id) FROM ipam_ip_address i LEFT JOIN dcim_port p ON p.id = i.port_id LEFT JOIN dcim_device d ON d.id = p.device_id
                LEFT JOIN virt_interface vi ON vi.id = i.vm_interface_id LEFT JOIN virt_machine vm ON vm.id = vi.vm_id
                WHERE host(i.address) = ? AND i.id <> ?
                SQL, [$host, $id ?? 0]);
            if (false !== $dup) {
                throw new ValidationException(['address' => \sprintf('%s já está em uso (%s).', $host, $dup)]);
            }
            if (null === $id) {
                $id = (int) $this->db->fetchOne(
                    'INSERT INTO ipam_ip_address (address, port_id, vm_interface_id, status, role, dns_name, description, comments, created_at, updated_at)
                     VALUES (CAST(? AS inet), ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                    [...array_values($row), Refs::now(), Refs::now()],
                );
            } else {
                $old = $this->db->fetchAssociative('SELECT port_id, vm_interface_id FROM ipam_ip_address WHERE id = ?', [$id]) ?: throw new NotFoundException('Endereço IP não encontrado.');
                $this->db->update('ipam_ip_address', $row + ['updated_at' => Refs::now()], ['id' => $id]);
                if ($old['port_id'] !== $row['port_id']) {
                    // saiu do equipamento: deixa de ser o primário dele
                    $this->db->executeStatement('UPDATE dcim_device SET primary_ip_id = NULL WHERE primary_ip_id = ? AND id NOT IN (SELECT device_id FROM dcim_port WHERE id = ?)', [$id, $row['port_id'] ?? 0]);
                }
                if ($old['vm_interface_id'] !== $row['vm_interface_id']) {
                    $this->db->executeStatement('UPDATE virt_machine SET primary_ip_id = NULL WHERE primary_ip_id = ? AND id NOT IN (SELECT vm_id FROM virt_interface WHERE id = ?)', [$id, $row['vm_interface_id'] ?? 0]);
                }
            }
            if ($makePrimary && \is_array($port)) {
                $this->db->executeStatement('UPDATE dcim_device SET primary_ip_id = ?, updated_at = ? WHERE id = ?', [$id, Refs::now(), $port['device_id']]);
            }
            if ($makePrimary && \is_array($vmIface)) {
                $this->db->executeStatement('UPDATE virt_machine SET primary_ip_id = ?, updated_at = ? WHERE id = ?', [$id, Refs::now(), $vmIface['vm_id']]);
            }

            return $id;
        });
    }

    public function delete(int $id): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM ipam_ip_address WHERE id = ?', [$id])) {
            throw new NotFoundException('Endereço IP não encontrado.');
        }
    }

    private function containingLen(string $host): ?int
    {
        $len = $this->db->fetchOne(
            "SELECT masklen(prefix) FROM ipam_prefix WHERE prefix >>= CAST(? AS inet) AND status <> 'container' ORDER BY masklen(prefix) DESC LIMIT 1",
            [$host],
        );

        return false === $len ? null : (int) $len;
    }
}
