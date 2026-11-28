<?php

declare(strict_types=1);

namespace App\Wifi\Service;

use App\Ui\Write\NotFoundException;
use App\Ui\Write\ValidationException;
use App\Wifi\Unifi\UnifiException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Leva para o UniFi o filtro de MAC de uma rede (ADR-0020):
 *   lista nova = (lista atual no UniFi − MACs que o painel retira) ∪ MACs ativos do painel.
 * O que já estava no UniFi e o painel não gerencia é preservado. Uma aplicação por rede por vez
 * (advisory lock), PUT parcial só do filtro e conferência lendo de novo depois de gravar.
 */
final readonly class WlanApplier
{
    private const int LOCK_CLASS = 0x57494649; // 'WIFI'
    public const int MAX_MACS = 512;

    public function __construct(
        private Connection $db,
        private ControllerService $controllers,
        #[Autowire(service: 'monolog.logger.wifi')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{wlan_id: int, changed: bool, added: list<string>, removed: list<string>, total: int, warnings: list<string>, error: ?string}
     */
    public function apply(int $wlanId): array
    {
        $w = $this->db->fetchAssociative(<<<'SQL'
            SELECT w.id, w.external_id, w.name, w.present, s.site_ref, s.controller_id, c.enabled AS controller_enabled, c.write_client_alias
            FROM unifi_wlan w JOIN unifi_site s ON s.id = w.site_id JOIN unifi_controller c ON c.id = s.controller_id WHERE w.id = ?
            SQL, [$wlanId]) ?: throw new NotFoundException('Rede Wi-Fi não encontrada.');
        if (!$w['present']) {
            throw new ValidationException(['wlan' => 'Esta rede não existe mais no UniFi (sumiu na última sincronização).']);
        }
        if (!$w['controller_enabled']) {
            throw new ValidationException(['wlan' => 'O controller desta rede está desativado.']);
        }
        if (!(bool) $this->db->fetchOne('SELECT pg_try_advisory_lock(?, ?)', [self::LOCK_CLASS, $wlanId])) {
            throw new ValidationException(['wlan' => 'Outra aplicação nesta rede está em andamento. Tente de novo em alguns segundos.']);
        }
        try {
            return $this->applyLocked($w);
        } finally {
            $this->db->fetchOne('SELECT pg_advisory_unlock(?, ?)', [self::LOCK_CLASS, $wlanId]);
        }
    }

    /**
     * @param array<string, mixed> $w
     *
     * @return array{wlan_id: int, changed: bool, added: list<string>, removed: list<string>, total: int, warnings: list<string>, error: ?string}
     */
    private function applyLocked(array $w): array
    {
        $wlanId = (int) $w['id'];
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT a.id, a.state, i.id AS identity_id, i.mac, i.person_name, i.department, i.registration, i.device_brand, i.device_model, i.hostname,
                   (i.status = 'active' AND (i.valid_until IS NULL OR i.valid_until > now())) AS wanted
            FROM mac_wlan_assignment a JOIN mac_identity i ON i.id = a.identity_id WHERE a.wlan_id = ?
            SQL, [$wlanId]);
        $want = [];
        $drop = [];
        foreach ($rows as $r) {
            if ($r['wanted'] && 'removing' !== $r['state']) {
                $want[$r['mac']] = $r;
            } else {
                $drop[$r['mac']] = $r;
            }
        }
        $result = ['wlan_id' => $wlanId, 'changed' => false, 'added' => [], 'removed' => [], 'total' => 0, 'warnings' => [], 'error' => null];

        $conn = $this->controllers->connect((int) $w['controller_id']);
        try {
            $live = $conn->wlan((string) $w['site_ref'], (string) $w['external_id']);
            if ('deny' === $live['filter_policy']) {
                throw new UnifiException('Esta rede usa o filtro em modo BLOQUEIO (deny): incluir o MAC bloquearia o aparelho. O painel só gerencia listas de permissão (allow).');
            }
            if (!$live['filter_enabled']) {
                $result['warnings'][] = 'O filtro de MAC desta rede está DESLIGADO no UniFi: a lista foi gravada, mas qualquer aparelho com a senha conecta.';
            }
            $current = $live['macs'];
            $target = array_values(array_unique(array_merge(array_values(array_diff($current, array_keys($drop))), array_keys($want))));
            if (\count($target) > self::MAX_MACS) {
                throw new UnifiException(\sprintf('A lista ficaria com %d MACs; o UniFi aceita até %d por rede.', \count($target), self::MAX_MACS));
            }
            $result['added'] = array_values(array_diff($target, $current));
            $result['removed'] = array_values(array_diff($current, $target));
            $result['total'] = \count($target);

            if ([] !== $result['added'] || [] !== $result['removed']) {
                $conn->setMacFilter((string) $w['site_ref'], (string) $w['external_id'], $target, $live['filter_enabled'], $live['filter_policy'] ?? 'allow');
                $check = $conn->wlan((string) $w['site_ref'], (string) $w['external_id']);
                $missing = array_diff(array_keys($want), $check['macs']);
                $stale = array_intersect(array_keys($drop), $check['macs']);
                if ([] !== $missing || [] !== $stale) {
                    throw new UnifiException('O UniFi aceitou a gravação, mas a lista lida de volta não confere (outra alteração ao mesmo tempo?). Sincronize e aplique de novo.');
                }
                $current = $check['macs'];
                $result['changed'] = true;
            }

            if ($w['write_client_alias'] && [] !== $result['added']) {
                try {
                    $known = $conn->knownClients((string) $w['site_ref']);
                    foreach ($result['added'] as $mac) {
                        if (isset($want[$mac])) {
                            [$name, $note] = self::alias($want[$mac]);
                            $conn->setClientAlias((string) $w['site_ref'], $mac, $name, $note, $known);
                        }
                    }
                } catch (UnifiException $e) {
                    $result['warnings'][] = 'Filtro aplicado, mas o nome do cliente no UniFi não foi gravado: '.$e->getMessage();
                }
            }
        } catch (UnifiException $e) {
            $result['error'] = $e->getMessage();
            $this->db->executeStatement(
                "UPDATE mac_wlan_assignment SET last_error = ?, updated_at = now() WHERE wlan_id = ? AND state <> 'synced'",
                [$e->getMessage(), $wlanId]
            );
            $this->logger->warning('Aplicação do filtro de MAC falhou', ['wlan' => $wlanId, 'error' => $e->getMessage()]);

            return $result;
        } finally {
            $conn->logout();
        }

        $this->db->transactional(function () use ($wlanId, $want, $drop, $current): void {
            if ([] !== $want) {
                $this->db->executeStatement(
                    "UPDATE mac_wlan_assignment SET state = 'synced', last_error = NULL, synced_at = now(), updated_at = now() WHERE id = ANY(CAST(? AS int[]))",
                    ['{'.implode(',', array_column($want, 'id')).'}']
                );
            }
            if ([] !== $drop) {
                $this->db->executeStatement('DELETE FROM mac_wlan_assignment WHERE id = ANY(CAST(? AS int[]))', ['{'.implode(',', array_column($drop, 'id')).'}']);
            }
            $this->db->executeStatement('UPDATE unifi_wlan SET remote_macs = CAST(? AS jsonb), synced_at = now(), updated_at = now() WHERE id = ?', [json_encode(array_values($current)), $wlanId]);
        });
        $this->logger->info('Filtro de MAC aplicado', ['wlan' => $wlanId, 'added' => \count($result['added']), 'removed' => \count($result['removed']), 'total' => $result['total']]);

        return $result;
    }

    /**
     * Liga o filtro de MAC em modo permissão — ação explícita: daí em diante só os MACs da lista conectam.
     *
     * @return array<string, mixed>
     */
    public function enableFilter(int $wlanId, string $confirmName): array
    {
        $w = $this->db->fetchAssociative(<<<'SQL'
            SELECT w.id, w.external_id, w.name, s.site_ref, s.controller_id FROM unifi_wlan w JOIN unifi_site s ON s.id = w.site_id WHERE w.id = ? AND w.present
            SQL, [$wlanId]) ?: throw new NotFoundException('Rede Wi-Fi não encontrada.');
        if (trim($confirmName) !== $w['name']) {
            throw new ValidationException(['confirm' => 'Digite o nome exato da rede para confirmar.']);
        }
        $result = $this->apply($wlanId); // garante que os MACs do painel já estão na lista antes de travar a rede
        if (null !== $result['error']) {
            throw new UnifiException($result['error']);
        }
        $conn = $this->controllers->connect((int) $w['controller_id']);
        try {
            $live = $conn->wlan((string) $w['site_ref'], (string) $w['external_id']);
            if ([] === $live['macs']) {
                throw new ValidationException(['confirm' => 'A lista está vazia: ligar o filtro agora desconectaria todo mundo desta rede.']);
            }
            $conn->setMacFilter((string) $w['site_ref'], (string) $w['external_id'], $live['macs'], true, 'allow');
            $check = $conn->wlan((string) $w['site_ref'], (string) $w['external_id']);
        } finally {
            $conn->logout();
        }
        $this->db->executeStatement(
            'UPDATE unifi_wlan SET filter_enabled = ?, filter_policy = ?, remote_macs = CAST(? AS jsonb), updated_at = now() WHERE id = ?',
            [$check['filter_enabled'], $check['filter_policy'], json_encode($check['macs']), $wlanId],
            [0 => 'boolean']
        );

        return ['filter_enabled' => $check['filter_enabled'], 'filter_policy' => $check['filter_policy'], 'total' => \count($check['macs'])];
    }

    /** @return list<int> redes com algo para levar ao UniFi */
    public function pendingWlans(): array
    {
        return array_map('intval', $this->db->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT a.wlan_id FROM mac_wlan_assignment a
            JOIN unifi_wlan w ON w.id = a.wlan_id AND w.present
            JOIN unifi_site s ON s.id = w.site_id JOIN unifi_controller c ON c.id = s.controller_id AND c.enabled
            WHERE a.state IN ('pending', 'removing') ORDER BY 1
            SQL));
    }

    /**
     * Nome e nota do cliente no UniFi: "Pessoa — Marca Modelo" / "Setor · Matrícula (GLPI Bianchi)".
     *
     * @param array<string, mixed> $r
     *
     * @return array{0: string, 1: string}
     */
    private static function alias(array $r): array
    {
        $device = trim(implode(' ', array_filter([$r['device_brand'], $r['device_model']])));
        $name = implode(' — ', array_filter([$r['person_name'], '' !== $device ? $device : $r['hostname']]));
        $note = implode(' · ', array_filter([$r['department'], null !== $r['registration'] ? 'mat. '.$r['registration'] : null]));

        return [mb_substr('' !== $name ? $name : (string) $r['mac'], 0, 64), mb_substr(('' !== $note ? $note.' ' : '').'(GLPI Bianchi #'.$r['identity_id'].')', 0, 255)];
    }
}
