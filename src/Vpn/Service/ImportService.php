<?php

declare(strict_types=1);

namespace App\Vpn\Service;

use App\Ui\Write\NotFoundException;
use App\Ui\Write\ValidationException;
use App\Vpn\Import\FortiConfigParser;
use App\Vpn\Import\FortiVpnExtractor;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Importa túneis IPsec da configuração do FortiGate (ADR-0022). O texto colado só existe em memória:
 * nada dele é gravado além dos parâmetros extraídos (sem segredos). Reimportar atualiza pelo nome
 * (equipamento + VDOM + fase 1); túnel que sumiu da config fica `present = false` (não apaga a documentação).
 */
final readonly class ImportService
{
    public function __construct(
        private Connection $db,
        private FortiConfigParser $parser,
        private FortiVpnExtractor $extractor,
        private Pairing $pairing,
        #[Autowire(service: 'monolog.logger.vpn')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Prévia: o que seria criado/alterado/marcado como ausente, sem gravar.
     *
     * @return array<string, mixed>
     */
    public function preview(int $deviceId, string $text): array
    {
        [$device, $data] = $this->read($deviceId, $text);
        $existing = $this->existing($deviceId);
        $rows = [];
        $seen = [];
        foreach ($data['tunnels'] as $t) {
            $key = $t['vdom']."\0".$t['name'];
            $seen[$key] = true;
            $old = $existing[$key] ?? null;
            $rows[] = [
                'name' => $t['name'], 'vdom' => $t['vdom'], 'mode' => $t['mode'], 'topology' => $t['topology'], 'remote_gw' => $t['remote_gw'],
                'local_ip' => $t['local_ip'], 'ike_version' => $t['ike_version'], 'phase2' => \count($t['phase2']),
                'routes' => \count($t['routes']), 'action' => null === $old ? 'new' : ($old['fingerprint'] === self::fingerprint($t) ? 'unchanged' : 'update'),
            ];
        }
        $missing = [];
        foreach ($existing as $key => $old) {
            if (!isset($seen[$key]) && $old['present']) {
                $missing[] = ['name' => $old['name'], 'vdom' => $old['vdom']];
            }
        }

        return [
            'device' => $device, 'hostname' => $data['hostname'], 'model' => $data['model'], 'version' => $data['version'],
            'secrets_removed' => $data['secrets_removed'], 'warnings' => $this->warnings($device, $data), 'tunnels' => $rows, 'missing' => $missing,
        ];
    }

    /** @return array{created: int, updated: int, unchanged: int, missing: int, secrets_removed: int} */
    public function apply(int $deviceId, string $text): array
    {
        [, $data] = $this->read($deviceId, $text);
        $existing = $this->existing($deviceId);
        $count = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0, 'secrets_removed' => $data['secrets_removed']];
        $this->db->transactional(function () use ($deviceId, $data, $existing, &$count): void {
            $seen = [];
            foreach ($data['tunnels'] as $t) {
                $key = $t['vdom']."\0".$t['name'];
                $old = $existing[$key] ?? null;
                $fp = self::fingerprint($t);
                $row = [
                    'mode' => $t['mode'], 'topology' => $t['topology'], 'local_interface' => $t['local_interface'], 'remote_gw' => $t['remote_gw'],
                    'ike_version' => $t['ike_version'], 'ike_mode' => $t['ike_mode'], 'auth_method' => $t['auth_method'], 'p1_proposal' => $t['p1_proposal'],
                    'p1_dhgroups' => $t['p1_dhgroups'], 'p1_keylife' => $t['p1_keylife'], 'dpd' => $t['dpd'], 'nat_traversal' => $t['nat_traversal'],
                    'tunnel_ip' => $t['tunnel_ip'], 'tunnel_remote_ip' => $t['tunnel_remote_ip'], 'routes' => json_encode($t['routes']),
                    'sdwan_zone' => $t['sdwan_zone'], 'params' => json_encode((object) $t['params'], \JSON_UNESCAPED_UNICODE),
                    'secrets_removed' => min(32767, $data['secrets_removed']), 'present' => 'true', 'imported_at' => date('c'), 'updated_at' => date('c'),
                ];
                if (null === $old) {
                    $this->db->insert('vpn_tunnel', $row + ['device_id' => $deviceId, 'vdom' => $t['vdom'], 'name' => $t['name'], 'status' => 'active',
                        'local_ip' => $t['local_ip'], 'description' => $t['description'], 'created_at' => date('c')]);
                    $id = (int) $this->db->lastInsertId();
                    ++$count['created'];
                } else {
                    $id = (int) $old['id'];
                    // IP local manual (WAN com DHCP/PPPoE) e descrição editada na tela são preservados
                    $this->db->update('vpn_tunnel', $row + (null !== $t['local_ip'] ? ['local_ip' => $t['local_ip']] : [])
                        + (null !== $t['description'] && null === $old['description'] ? ['description' => $t['description']] : []), ['id' => $id]);
                    ++$count[$old['fingerprint'] === $fp ? 'unchanged' : 'updated'];
                }
                $this->storePhase2($id, $t['phase2']);
                $seen[] = $id;
            }
            $count['missing'] = (int) $this->db->executeStatement(
                'UPDATE vpn_tunnel SET present = false, updated_at = now() WHERE device_id = ? AND present AND NOT (id = ANY(CAST(? AS int[])))',
                [$deviceId, '{'.implode(',', $seen).'}']
            );
            $this->pairing->recompute();
        });
        $this->logger->info('Túneis IPsec importados', ['device' => $deviceId] + $count);

        return $count;
    }

    /** @return array{0: array{id: int, name: string, branch: ?string}, 1: array<string, mixed>} */
    private function read(int $deviceId, string $text): array
    {
        $device = $this->db->fetchAssociative('SELECT d.id, d.name, b.name AS branch FROM dcim_device d LEFT JOIN branch b ON b.id = d.branch_id WHERE d.id = ?', [$deviceId]);
        if (false === $device) {
            throw new NotFoundException('Equipamento não encontrado.');
        }
        if ('' === trim($text)) {
            throw new ValidationException(['config_text' => 'Cole a configuração do FortiGate.']);
        }
        try {
            $data = $this->extractor->extract($this->parser->parse($text));
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(['config_text' => $e->getMessage()]);
        }
        if ([] === $data['tunnels']) {
            throw new ValidationException(['config_text' => $data['warnings'][0] ?? 'Nenhum túnel encontrado.']);
        }

        return [['id' => (int) $device['id'], 'name' => (string) $device['name'], 'branch' => $device['branch']], $data];
    }

    /**
     * @param array{id: int, name: string, branch: ?string} $device
     * @param array<string, mixed>                          $data
     *
     * @return list<string>
     */
    private function warnings(array $device, array $data): array
    {
        $w = $data['warnings'];
        if (null !== $data['hostname'] && 0 !== strcasecmp((string) $data['hostname'], $device['name'])) {
            $w[] = \sprintf('A configuração é do FortiGate "%s", mas você escolheu o equipamento "%s". Confira antes de importar.', $data['hostname'], $device['name']);
        }
        if ($data['secrets_removed'] > 0) {
            $w[] = \sprintf('%d parâmetro(s) secreto(s) (PSK, senhas) foram descartados e não serão gravados.', $data['secrets_removed']);
        }

        return $w;
    }

    /** @return array<string, array<string, mixed>> */
    private function existing(int $deviceId): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative('SELECT * FROM vpn_tunnel WHERE device_id = ?', [$deviceId]) as $r) {
            $p2 = $this->db->fetchAllAssociative('SELECT name, proposal, pfs, dhgroups, keylife_seconds, auto_negotiate, src::text AS src, dst::text AS dst FROM vpn_phase2 WHERE tunnel_id = ? ORDER BY name', [$r['id']]);
            $r['fingerprint'] = self::fingerprint([
                'mode' => $r['mode'], 'local_interface' => $r['local_interface'], 'remote_gw' => $r['remote_gw'], 'ike_version' => (int) $r['ike_version'],
                'p1_proposal' => $r['p1_proposal'], 'p1_dhgroups' => $r['p1_dhgroups'], 'p1_keylife' => null === $r['p1_keylife'] ? null : (int) $r['p1_keylife'],
                'routes' => json_decode((string) $r['routes'], true), 'tunnel_ip' => $r['tunnel_ip'],
                'phase2' => array_map(static fn (array $x): array => ['name' => $x['name'], 'proposal' => $x['proposal'], 'dhgroups' => $x['dhgroups'],
                    'src' => json_decode((string) $x['src'], true), 'dst' => json_decode((string) $x['dst'], true)], $p2),
            ]);
            $out[$r['vdom']."\0".$r['name']] = $r;
        }

        return $out;
    }

    /** @param array<string, mixed> $t */
    private static function fingerprint(array $t): string
    {
        $p2 = array_map(static fn (array $x): array => [$x['name'], $x['proposal'], $x['dhgroups'], $x['src'], $x['dst']], $t['phase2'] ?? []);
        usort($p2, static fn (array $a, array $b): int => strcmp((string) $a[0], (string) $b[0]));

        return hash('sha256', (string) json_encode([$t['mode'], $t['local_interface'], $t['remote_gw'], (int) $t['ike_version'], $t['p1_proposal'],
            $t['p1_dhgroups'], $t['p1_keylife'], $t['routes'], $t['tunnel_ip'], $p2]));
    }

    /** @param list<array<string, mixed>> $phase2 */
    private function storePhase2(int $tunnelId, array $phase2): void
    {
        $names = [];
        foreach ($phase2 as $p) {
            $names[] = $p['name'];
            $this->db->executeStatement(
                <<<'SQL'
                    INSERT INTO vpn_phase2 (tunnel_id, name, proposal, pfs, dhgroups, keylife_seconds, auto_negotiate, src, dst, src_name, dst_name, params, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), ?, ?, CAST(? AS jsonb), now(), now())
                    ON CONFLICT (tunnel_id, name) DO UPDATE SET proposal = EXCLUDED.proposal, pfs = EXCLUDED.pfs, dhgroups = EXCLUDED.dhgroups,
                        keylife_seconds = EXCLUDED.keylife_seconds, auto_negotiate = EXCLUDED.auto_negotiate, src = EXCLUDED.src, dst = EXCLUDED.dst,
                        src_name = EXCLUDED.src_name, dst_name = EXCLUDED.dst_name, params = EXCLUDED.params, updated_at = now()
                    SQL,
                [$tunnelId, mb_substr((string) $p['name'], 0, 64), $p['proposal'], $p['pfs'], $p['dhgroups'], $p['keylife_seconds'], $p['auto_negotiate'],
                    json_encode($p['src']), json_encode($p['dst']), null === $p['src_name'] ? null : mb_substr((string) $p['src_name'], 0, 80),
                    null === $p['dst_name'] ? null : mb_substr((string) $p['dst_name'], 0, 80), json_encode((object) $p['params'], \JSON_UNESCAPED_UNICODE)],
                [3 => 'boolean', 6 => 'boolean']
            );
        }
        $this->db->executeStatement(
            'DELETE FROM vpn_phase2 WHERE tunnel_id = ? AND NOT (name = ANY(CAST(? AS text[])))',
            [$tunnelId, '{'.implode(',', array_map(static fn (string $n): string => '"'.addcslashes($n, '"\\').'"', $names)).'}']
        );
    }
}
