<?php

declare(strict_types=1);

namespace App\Vpn\Service;

use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use Doctrine\DBAL\Connection;

/** Consulta e documentação dos túneis IPsec (ADR-0022). Parâmetros técnicos vêm da importação; aqui só o que é documentação. */
final readonly class TunnelService
{
    public const array STATUS = ['active' => 'Ativo', 'disabled' => 'Desativado', 'planned' => 'Planejado', 'decommissioning' => 'Em desativação'];
    public const array TOPOLOGY = ['site_to_site' => 'Site a site', 'dialup' => 'Dial-up (FortiClient / remoto)', 'hub' => 'Hub (ADVPN)', 'spoke' => 'Spoke (ADVPN)'];

    private const string SELECT = <<<'SQL'
        SELECT t.id, t.name, t.vdom, t.mode, t.topology, t.status, t.present, t.local_interface, t.local_ip, t.remote_gw, t.ike_version, t.ike_mode,
               t.auth_method, t.p1_proposal, t.p1_dhgroups, t.p1_keylife, t.dpd, t.nat_traversal, t.tunnel_ip, t.tunnel_remote_ip, t.routes, t.sdwan_zone,
               t.params, t.peer_label, t.psk_location, t.description, t.comments, t.secrets_removed, t.imported_at, t.updated_at,
               t.device_id, d.name AS device, d.branch_id, b.name AS branch,
               t.peer_tunnel_id, pt.name AS peer_tunnel, pd.id AS peer_device_id, pd.name AS peer_device, pb.name AS peer_branch,
               t.supplier_id, s.name AS supplier, t.circuit_id, c.cid AS circuit,
               (SELECT count(*) FROM vpn_phase2 p WHERE p.tunnel_id = t.id) AS phase2_count
        FROM vpn_tunnel t
        JOIN dcim_device d ON d.id = t.device_id
        LEFT JOIN branch b ON b.id = d.branch_id
        LEFT JOIN vpn_tunnel pt ON pt.id = t.peer_tunnel_id
        LEFT JOIN dcim_device pd ON pd.id = pt.device_id
        LEFT JOIN branch pb ON pb.id = pd.branch_id
        LEFT JOIN supplier s ON s.id = t.supplier_id
        LEFT JOIN circuit c ON c.id = t.circuit_id
        SQL;

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param array{q?: ?string, device?: ?int, branch?: ?int, status?: ?string, topology?: ?string, id?: ?int} $f
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $f = []): array
    {
        $rows = $this->db->fetchAllAssociative(self::SELECT.<<<'SQL'

            WHERE (CAST(:id AS int) IS NULL OR t.id = :id)
              AND (CAST(:device AS int) IS NULL OR t.device_id = :device)
              AND (CAST(:branch AS int) IS NULL OR d.branch_id = :branch)
              AND (CAST(:status AS text) IS NULL OR t.status = :status)
              AND (CAST(:topology AS text) IS NULL OR t.topology = :topology)
              AND (CAST(:q AS text) IS NULL OR t.name ILIKE '%' || CAST(:q AS text) || '%' OR t.remote_gw ILIKE CAST(:q AS text) || '%'
                   OR t.local_ip ILIKE CAST(:q AS text) || '%' OR t.peer_label ILIKE '%' || CAST(:q AS text) || '%' OR d.name ILIKE '%' || CAST(:q AS text) || '%'
                   OR t.description ILIKE '%' || CAST(:q AS text) || '%' OR s.name ILIKE '%' || CAST(:q AS text) || '%'
                   OR EXISTS (SELECT 1 FROM vpn_phase2 p, jsonb_array_elements_text(p.src || p.dst) n(net)
                              WHERE p.tunnel_id = t.id AND n.net LIKE CAST(:q AS text) || '%'))
            ORDER BY b.name NULLS LAST, d.name, t.name
            LIMIT 2000
            SQL, ['id' => $f['id'] ?? null, 'device' => $f['device'] ?? null, 'branch' => $f['branch'] ?? null, 'status' => $f['status'] ?? null,
            'topology' => $f['topology'] ?? null, 'q' => $f['q'] ?? null]);

        return array_map(static function (array $r): array {
            $r['routes'] = json_decode((string) $r['routes'], true);
            $r['params'] = json_decode((string) $r['params'], true);
            foreach (['id', 'device_id', 'phase2_count', 'ike_version', 'p1_keylife', 'secrets_removed'] as $k) {
                $r[$k] = null === $r[$k] ? null : (int) $r[$k];
            }

            return $r;
        }, $rows);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $t = $this->list(['id' => $id])[0] ?? throw new NotFoundException('Túnel não encontrado.');
        $t['phase2'] = $this->phase2($id);
        $t['networks'] = $this->networks($t);
        $t['compat'] = null;
        $peerId = $t['peer_tunnel_id'] ?? $this->db->fetchOne('SELECT id FROM vpn_tunnel WHERE peer_tunnel_id = ? ORDER BY id LIMIT 1', [$id]);
        if (false !== $peerId && null !== $peerId) {
            $peer = $this->list(['id' => (int) $peerId])[0] ?? null;
            if (null !== $peer) {
                $peer['phase2'] = $this->phase2((int) $peerId);
                $checks = TunnelCompat::check($t, $peer, $t['phase2'], $peer['phase2']);
                $t['peer'] = $peer;
                $t['compat'] = ['verdict' => TunnelCompat::verdict($checks), 'checks' => $checks];
            }
        }
        $t['spokes'] = \in_array($t['topology'], ['hub', 'dialup'], true)
            ? $this->db->fetchAllAssociative('SELECT t.id, t.name, d.name AS device FROM vpn_tunnel t JOIN dcim_device d ON d.id = t.device_id WHERE t.peer_tunnel_id = ? ORDER BY d.name', [$id])
            : [];

        return $t;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->detail($id);
        $p = new Payload($data);
        $supplier = $p->int('supplier_id', 1);
        $circuit = $p->int('circuit_id', 1);
        $peer = $p->int('peer_tunnel_id', 1);
        $localIp = $p->string('local_ip', 64);
        if (null !== $localIp && false === filter_var($localIp, \FILTER_VALIDATE_IP)) {
            $p->error('local_ip', 'IP inválido.');
        }
        if (null !== $supplier && false === $this->db->fetchOne('SELECT 1 FROM supplier WHERE id = ?', [$supplier])) {
            $p->error('supplier_id', 'Fornecedor não encontrado.');
        }
        if (null !== $circuit && false === $this->db->fetchOne('SELECT 1 FROM circuit WHERE id = ?', [$circuit])) {
            $p->error('circuit_id', 'Circuito não encontrado.');
        }
        if (null !== $peer && ($peer === $id || false === $this->db->fetchOne('SELECT 1 FROM vpn_tunnel WHERE id = ? AND device_id <> (SELECT device_id FROM vpn_tunnel WHERE id = ?)', [$peer, $id]))) {
            $p->error('peer_tunnel_id', 'O outro lado precisa ser um túnel de outro FortiGate.');
        }
        $psk = $p->string('psk_location', 200);
        if (null !== $psk && 1 === preg_match('/^[A-Za-z0-9+\/=]{20,}$/', $psk) && 1 !== preg_match('/\s/', $psk)) {
            $p->error('psk_location', 'Isso parece a própria chave. Informe só ONDE ela está (ex.: "Cofre TI › VPN › Cascavel").');
        }
        $row = [
            'status' => $p->enum('status', array_keys(self::STATUS), 'active'), 'peer_label' => $p->string('peer_label', 120),
            'supplier_id' => $supplier, 'circuit_id' => $circuit, 'peer_tunnel_id' => $peer, 'local_ip' => $localIp, 'psk_location' => $psk,
            'description' => $p->string('description', 255), 'comments' => $p->string('comments', 10000), 'updated_at' => date('c'),
        ];
        $p->check();
        $this->db->update('vpn_tunnel', $row, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM vpn_tunnel WHERE id = ?', [$id])) {
            throw new NotFoundException('Túnel não encontrado.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function phase2(int $tunnelId): array
    {
        return array_map(static function (array $r): array {
            foreach (['src', 'dst', 'params'] as $k) {
                $r[$k] = json_decode((string) $r[$k], true);
            }
            $r['keylife_seconds'] = null === $r['keylife_seconds'] ? null : (int) $r['keylife_seconds'];

            return $r;
        }, $this->db->fetchAllAssociative('SELECT id, name, proposal, pfs, dhgroups, keylife_seconds, auto_negotiate, src, dst, src_name, dst_name, params FROM vpn_phase2 WHERE tunnel_id = ? ORDER BY name', [$tunnelId]));
    }

    /**
     * Redes citadas (seletores + rotas) com o prefixo do IPAM que as contém e a filial dele.
     *
     * @param array<string, mixed> $t
     *
     * @return list<array<string, mixed>>
     */
    private function networks(array $t): array
    {
        $nets = [];
        foreach ($t['phase2'] as $p) {
            foreach ($p['src'] as $n) {
                $nets[$n]['local'] = true;
            }
            foreach ($p['dst'] as $n) {
                $nets[$n]['remote'] = true;
            }
        }
        foreach ($t['routes'] as $n) {
            $nets[$n]['route'] = true;
        }
        $out = [];
        foreach ($nets as $net => $flags) {
            $prefix = 1 === preg_match('#^[0-9a-f.:]+/\d+$#i', (string) $net) && !\in_array($net, ['0.0.0.0/0', '::/0'], true)
                ? $this->db->fetchAssociative(<<<'SQL'
                    SELECT p.id, p.prefix::text AS prefix, p.description, b.name AS branch, v.vid AS vlan_vid, v.name AS vlan
                    FROM ipam_prefix p LEFT JOIN branch b ON b.id = p.branch_id LEFT JOIN ipam_vlan v ON v.id = p.vlan_id
                    WHERE p.prefix >>= CAST(? AS inet) ORDER BY masklen(p.prefix) DESC LIMIT 1
                    SQL, [$net])
                : false;
            $out[] = ['net' => $net, 'local' => $flags['local'] ?? false, 'remote' => $flags['remote'] ?? false, 'route' => $flags['route'] ?? false,
                'prefix' => false === $prefix ? null : $prefix];
        }

        return $out;
    }
}
