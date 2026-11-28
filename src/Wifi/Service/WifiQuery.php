<?php

declare(strict_types=1);

namespace App\Wifi\Service;

use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use Doctrine\DBAL\Connection;

/** Leitura para a tela de acesso Wi-Fi: árvore controller › site › rede, com contagens por estado. */
final readonly class WifiQuery
{
    public function __construct(private Connection $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function tree(): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT c.id AS controller_id, c.name AS controller, c.enabled, c.last_sync_at, c.last_error, c.version,
                   s.id AS site_id, s.site_ref, coalesce(s.description, s.site_ref) AS site, s.branch_id, b.name AS branch,
                   w.id AS wlan_id, w.name AS wlan, w.enabled AS wlan_enabled, w.security, w.filter_enabled, w.filter_policy, w.synced_at,
                   jsonb_array_length(w.remote_macs) AS remote_count,
                   (SELECT count(*) FROM mac_wlan_assignment a WHERE a.wlan_id = w.id AND a.state = 'synced') AS synced,
                   (SELECT count(*) FROM mac_wlan_assignment a WHERE a.wlan_id = w.id AND a.state IN ('pending', 'removing')) AS pending,
                   (SELECT count(*) FROM mac_wlan_assignment a WHERE a.wlan_id = w.id AND a.state = 'error') AS errors,
                   (SELECT count(*) FROM jsonb_array_elements_text(w.remote_macs) m(mac)
                     WHERE NOT EXISTS (SELECT 1 FROM mac_wlan_assignment a JOIN mac_identity i ON i.id = a.identity_id WHERE a.wlan_id = w.id AND i.mac = m.mac)) AS unknown
            FROM unifi_controller c
            LEFT JOIN unifi_site s ON s.controller_id = c.id AND s.present
            LEFT JOIN branch b ON b.id = s.branch_id
            LEFT JOIN unifi_wlan w ON w.site_id = s.id AND w.present
            ORDER BY c.name, coalesce(s.description, s.site_ref), w.name
            SQL);
        $out = [];
        foreach ($rows as $r) {
            $c = &$out[$r['controller_id']];
            $c ??= ['id' => (int) $r['controller_id'], 'name' => $r['controller'], 'enabled' => (bool) $r['enabled'], 'last_sync_at' => $r['last_sync_at'],
                'last_error' => $r['last_error'], 'version' => $r['version'], 'sites' => []];
            if (null !== $r['site_id']) {
                $s = &$c['sites'][$r['site_id']];
                $s ??= ['id' => (int) $r['site_id'], 'ref' => $r['site_ref'], 'name' => $r['site'], 'branch_id' => $r['branch_id'], 'branch' => $r['branch'], 'wlans' => []];
                if (null !== $r['wlan_id']) {
                    $s['wlans'][] = ['id' => (int) $r['wlan_id'], 'name' => $r['wlan'], 'enabled' => (bool) $r['wlan_enabled'], 'security' => $r['security'],
                        'filter_enabled' => (bool) $r['filter_enabled'], 'filter_policy' => $r['filter_policy'], 'synced_at' => $r['synced_at'],
                        'remote_count' => (int) $r['remote_count'], 'synced' => (int) $r['synced'], 'pending' => (int) $r['pending'], 'errors' => (int) $r['errors'], 'unknown' => (int) $r['unknown']];
                }
                unset($s);
            }
            unset($c);
        }

        return array_values(array_map(static function (array $c): array {
            $c['sites'] = array_values($c['sites']);

            return $c;
        }, $out));
    }

    /** @return array<string, mixed> */
    public function wlan(int $id): array
    {
        $w = $this->db->fetchAssociative(<<<'SQL'
            SELECT w.id, w.name, w.enabled, w.security, w.filter_enabled, w.filter_policy, w.synced_at, w.present, jsonb_array_length(w.remote_macs) AS remote_count,
                   s.id AS site_id, coalesce(s.description, s.site_ref) AS site, s.site_ref, c.id AS controller_id, c.name AS controller, c.base_url, c.auth_mode
            FROM unifi_wlan w JOIN unifi_site s ON s.id = w.site_id JOIN unifi_controller c ON c.id = s.controller_id WHERE w.id = ?
            SQL, [$id]);
        if (false === $w) {
            throw new NotFoundException('Rede Wi-Fi não encontrada.');
        }
        $w['remote_count'] = (int) $w['remote_count'];

        return $w;
    }

    /** @param array<string, mixed> $data */
    public function linkSiteBranch(int $siteId, array $data): void
    {
        $p = new Payload($data);
        $branch = $p->int('branch_id', 1);
        if (null !== $branch && false === $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ?', [$branch])) {
            $p->error('branch_id', 'Filial não encontrada.');
        }
        $p->check();
        if (0 === $this->db->executeStatement('UPDATE unifi_site SET branch_id = ?, updated_at = now() WHERE id = ?', [$branch, $siteId])) {
            throw new NotFoundException('Site não encontrado.');
        }
    }
}
