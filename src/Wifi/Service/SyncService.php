<?php

declare(strict_types=1);

namespace App\Wifi\Service;

use App\Ui\Write\NotFoundException;
use App\Wifi\Unifi\UnifiException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Traz do controller os sites e as redes Wi-Fi com a lista atual do filtro de MAC (ADR-0020).
 * Site/rede que sumiu do UniFi fica `present = false` (não apaga: as identidades continuam ligadas).
 * MAC gerenciado pelo painel que alguém tirou direto no UniFi vira `error` ("removido no UniFi").
 */
final readonly class SyncService
{
    public function __construct(
        private Connection $db,
        private ControllerService $controllers,
        #[Autowire(service: 'monolog.logger.wifi')]
        private LoggerInterface $logger,
    ) {
    }

    /** @return array{sites: int, wlans: int, drift: int, version: ?string} */
    public function sync(int $controllerId): array
    {
        $conn = $this->controllers->connect($controllerId);
        try {
            $sites = $conn->sites();
            $wlansBySite = [];
            foreach ($sites as $s) {
                $wlansBySite[$s['ref']] = $conn->wlans($s['ref']);
            }
            $version = null === ($sites[0] ?? null) ? null : $conn->version($sites[0]['ref']);
            $platform = $conn->platform();
        } catch (UnifiException $e) {
            $this->db->executeStatement('UPDATE unifi_controller SET last_error = ?, updated_at = now() WHERE id = ?', [$e->getMessage(), $controllerId]);
            $this->logger->warning('Sincronização UniFi falhou', ['controller' => $controllerId, 'error' => $e->getMessage()]);
            throw $e;
        } finally {
            $conn->logout();
        }

        $count = ['sites' => 0, 'wlans' => 0, 'drift' => 0, 'version' => $version];
        $this->db->transactional(function () use ($controllerId, $sites, $wlansBySite, $version, $platform, &$count): void {
            $seenSites = [];
            foreach ($sites as $s) {
                $siteId = (int) $this->db->fetchOne(<<<'SQL'
                    INSERT INTO unifi_site (controller_id, site_ref, external_id, description, present, synced_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, true, now(), now(), now())
                    ON CONFLICT (controller_id, site_ref) DO UPDATE SET external_id = EXCLUDED.external_id, description = EXCLUDED.description,
                        present = true, synced_at = now(), updated_at = now()
                    RETURNING id
                    SQL, [$controllerId, $s['ref'], '' === $s['id'] ? null : $s['id'], mb_substr($s['description'], 0, 255)]);
                $seenSites[] = $siteId;
                ++$count['sites'];
                [$n, $drift] = $this->storeWlans($siteId, $wlansBySite[$s['ref']]);
                $count['wlans'] += $n;
                $count['drift'] += $drift;
            }
            $this->db->executeStatement(
                'UPDATE unifi_site SET present = false, updated_at = now() WHERE controller_id = ? AND present AND NOT (id = ANY(CAST(? AS int[])))',
                [$controllerId, '{'.implode(',', $seenSites).'}']
            );
            $this->db->executeStatement(
                'UPDATE unifi_controller SET last_sync_at = now(), last_error = NULL, version = coalesce(?, version), platform = ?, updated_at = now() WHERE id = ?',
                [$version, $platform, $controllerId]
            );
        });
        $this->logger->info('Sincronização UniFi concluída', ['controller' => $controllerId] + $count);

        return $count;
    }

    /**
     * Relê só um site (as redes Wi-Fi e os filtros de MAC dele) — botão "Sincronizar site".
     *
     * @return array{site: string, wlans: int, drift: int}
     */
    public function syncSite(int $siteId): array
    {
        $site = $this->db->fetchAssociative('SELECT id, controller_id, site_ref, coalesce(description, site_ref) AS name FROM unifi_site WHERE id = ? AND present', [$siteId])
            ?: throw new NotFoundException('Site não encontrado (sincronize o controller inteiro).');
        $conn = $this->controllers->connect((int) $site['controller_id']);
        try {
            $wlans = $conn->wlans((string) $site['site_ref']);
        } catch (UnifiException $e) {
            $this->logger->warning('Sincronização do site UniFi falhou', ['site' => $siteId, 'error' => $e->getMessage()]);
            throw $e;
        } finally {
            $conn->logout();
        }
        $r = $this->db->transactional(function () use ($siteId, $wlans): array {
            $r = $this->storeWlans($siteId, $wlans);
            $this->db->executeStatement('UPDATE unifi_site SET synced_at = now(), updated_at = now() WHERE id = ?', [$siteId]);

            return $r;
        });
        $this->logger->info('Sincronização do site UniFi concluída', ['site' => $siteId, 'wlans' => $r[0], 'drift' => $r[1]]);

        return ['site' => (string) $site['name'], 'wlans' => $r[0], 'drift' => $r[1]];
    }

    /**
     * Grava as redes de um site, marca as que sumiram e o "drift" dos MACs do painel.
     *
     * @param list<array{id: string, name: string, enabled: bool, security: string, filter_enabled: bool, filter_policy: ?string, macs: list<string>}> $wlans
     *
     * @return array{0: int, 1: int} redes gravadas, MACs removidos direto no UniFi
     */
    private function storeWlans(int $siteId, array $wlans): array
    {
        $seen = [];
        $drift = 0;
        foreach ($wlans as $w) {
            if ('' === $w['id']) {
                continue;
            }
            $wlanId = (int) $this->db->fetchOne(<<<'SQL'
                INSERT INTO unifi_wlan (site_id, external_id, name, enabled, security, filter_enabled, filter_policy, remote_macs, present, synced_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), true, now(), now(), now())
                ON CONFLICT (site_id, external_id) DO UPDATE SET name = EXCLUDED.name, enabled = EXCLUDED.enabled, security = EXCLUDED.security,
                    filter_enabled = EXCLUDED.filter_enabled, filter_policy = EXCLUDED.filter_policy, remote_macs = EXCLUDED.remote_macs,
                    present = true, synced_at = now(), updated_at = now()
                RETURNING id
                SQL, [$siteId, $w['id'], mb_substr($w['name'], 0, 100), $w['enabled'], mb_substr($w['security'], 0, 30), $w['filter_enabled'], $w['filter_policy'],
                json_encode($w['macs'])], [3 => 'boolean', 5 => 'boolean']);
            $seen[] = $wlanId;
            $drift += $this->markDrift($wlanId, $w['macs']);
        }
        $this->db->executeStatement(
            'UPDATE unifi_wlan SET present = false, updated_at = now() WHERE site_id = ? AND present AND NOT (id = ANY(CAST(? AS int[])))',
            [$siteId, '{'.implode(',', $seen).'}']
        );

        return [\count($seen), $drift];
    }

    /** @param list<string> $remote */
    private function markDrift(int $wlanId, array $remote): int
    {
        return (int) $this->db->executeStatement(<<<'SQL'
            UPDATE mac_wlan_assignment a SET state = 'error', last_error = 'Removido direto no UniFi. Clique em Aplicar para recolocar ou revogue a identidade.', updated_at = now()
            FROM mac_identity i
            WHERE i.id = a.identity_id AND a.wlan_id = ? AND a.state = 'synced' AND NOT jsonb_exists(CAST(? AS jsonb), i.mac)
            SQL, [$wlanId, json_encode($remote)]);
    }
}
