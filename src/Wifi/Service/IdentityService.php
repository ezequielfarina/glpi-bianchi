<?php

declare(strict_types=1);

namespace App\Wifi\Service;

use App\Identity\Security\CurrentActor;
use App\Tools\Scanner\MacVendors;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\ValidationException;
use App\Wifi\Unifi\MacAddress;
use Doctrine\DBAL\Connection;

/**
 * Identidade de cada MAC (pessoa + aparelho + validade) e em quais redes Wi-Fi ele deve estar (ADR-0020).
 * Gravar aqui só marca o que precisa ir para o UniFi (`pending`/`removing`); quem fala com o controller é o
 * WlanApplier — na hora ("Aplicar agora") ou pelo `app:wifi:sync` a cada 10 minutos.
 */
final readonly class IdentityService
{
    public const array DEVICE_KINDS = [
        'phone' => 'Celular', 'notebook' => 'Notebook', 'tablet' => 'Tablet', 'desktop' => 'Desktop', 'printer' => 'Impressora',
        'iot' => 'IoT / sensor', 'tv' => 'TV / painel', 'voip' => 'Telefone IP', 'scanner' => 'Coletor / leitor', 'other' => 'Outro',
    ];
    public const array STATES = ['pending' => 'Aguardando aplicar', 'synced' => 'No UniFi', 'error' => 'Com erro', 'removing' => 'Removendo'];

    public function __construct(
        private Connection $db,
        private MacVendors $vendors,
        private CurrentActor $actor,
    ) {
    }

    /**
     * @param array{q?: ?string, wlan?: ?int, status?: ?string, state?: ?string, id?: ?int} $f
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $f = []): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT i.id, i.mac, i.person_name, i.registration, i.department, i.email, i.phone, i.person_id, u.username AS person_username,
                   i.device_kind, i.device_brand, i.device_model, i.hostname, i.asset_id, a.uuid AS asset_uuid, a.name AS asset_name,
                   i.branch_id, b.name AS branch, i.valid_until, i.status, i.source, i.notes, i.created_by, i.created_at, i.updated_at,
                   (i.status = 'active' AND i.valid_until IS NOT NULL AND i.valid_until <= now()) AS expired,
                   coalesce((SELECT json_agg(json_build_object('wlan_id', w.id, 'wlan', w.name, 'site', coalesce(s.description, s.site_ref), 'controller', c.name,
                       'state', x.state, 'last_error', x.last_error, 'synced_at', x.synced_at) ORDER BY c.name, s.site_ref, w.name)
                     FROM mac_wlan_assignment x JOIN unifi_wlan w ON w.id = x.wlan_id JOIN unifi_site s ON s.id = w.site_id JOIN unifi_controller c ON c.id = s.controller_id
                     WHERE x.identity_id = i.id), '[]') AS wlans
            FROM mac_identity i
            LEFT JOIN app_user u ON u.id = i.person_id
            LEFT JOIN asset a ON a.id = i.asset_id
            LEFT JOIN branch b ON b.id = i.branch_id
            WHERE (CAST(:id AS int) IS NULL OR i.id = :id)
              AND (CAST(:status AS text) IS NULL OR i.status = :status)
              AND (CAST(:wlan AS int) IS NULL OR EXISTS (SELECT 1 FROM mac_wlan_assignment x WHERE x.identity_id = i.id AND x.wlan_id = :wlan))
              AND (CAST(:state AS text) IS NULL OR EXISTS (SELECT 1 FROM mac_wlan_assignment x WHERE x.identity_id = i.id AND x.state = :state))
              AND (CAST(:q AS text) IS NULL OR i.mac LIKE '%' || lower(CAST(:q AS text)) || '%'
                   OR replace(i.mac, ':', '') LIKE '%' || lower(regexp_replace(CAST(:q AS text), '[^0-9A-Fa-f]', '', 'g')) || '%' AND length(regexp_replace(CAST(:q AS text), '[^0-9A-Fa-f]', '', 'g')) >= 4
                   OR i.person_name ILIKE '%' || CAST(:q AS text) || '%' OR i.registration ILIKE CAST(:q AS text) || '%'
                   OR i.department ILIKE '%' || CAST(:q AS text) || '%' OR i.device_model ILIKE '%' || CAST(:q AS text) || '%'
                   OR i.hostname ILIKE '%' || CAST(:q AS text) || '%' OR a.name ILIKE '%' || CAST(:q AS text) || '%')
            ORDER BY i.person_name NULLS LAST, i.mac
            LIMIT 5000
            SQL, ['id' => $f['id'] ?? null, 'status' => $f['status'] ?? null, 'wlan' => $f['wlan'] ?? null, 'state' => $f['state'] ?? null, 'q' => $f['q'] ?? null]);
        foreach ($rows as &$r) {
            $r['wlans'] = json_decode((string) $r['wlans'], true);
            $r['vendor'] = $this->vendors->vendor(str_replace(':', '', (string) $r['mac']));
            $r['randomized'] = MacAddress::isRandomized((string) $r['mac']);
        }
        unset($r);

        return $rows;
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        return $this->list(['id' => $id])[0] ?? throw new NotFoundException('Identidade de MAC não encontrada.');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{id: int, wlans: list<int>} redes que precisam ser aplicadas
     */
    public function save(?int $id, array $data): array
    {
        $current = null === $id ? null : $this->detail($id);
        $p = new Payload($data);
        $mac = MacAddress::normalize((string) ($p->string('mac', 40, required: true) ?? ''));
        if (null === $mac) {
            $p->error('mac', 'MAC inválido. Aceita AA:BB:CC:DD:EE:FF, AA-BB-…, AABB.CCDD.EEFF ou só os 12 dígitos.');
        } elseif (MacAddress::isMulticast($mac)) {
            $p->error('mac', 'Endereço multicast — não é MAC de aparelho.');
        } elseif (false !== ($other = $this->db->fetchOne('SELECT coalesce(person_name, mac) FROM mac_identity WHERE mac = ? AND id <> ?', [$mac, $id ?? 0]))) {
            $p->error('mac', 'Este MAC já está cadastrado ('.$other.').');
        }
        if (null !== $current && $current['mac'] !== $mac && [] !== $current['wlans']) {
            $p->error('mac', 'Não dá para trocar o MAC de uma identidade que já está em redes: revogue e cadastre o novo.');
        }
        $personName = $p->string('person_name', 120);
        $personId = $p->int('person_id', 1);
        $assetUuid = $p->string('asset_uuid', 36);
        $branchId = $p->int('branch_id', 1);
        $validUntil = $p->date('valid_until');
        if (null !== $validUntil && $validUntil < date('Y-m-d')) {
            $p->error('valid_until', 'A validade já passou.');
        }
        $kind = $p->enum('device_kind', array_keys(self::DEVICE_KINDS), 'other');
        $wlanIds = [];
        foreach ((array) ($data['wlan_ids'] ?? []) as $v) {
            if (!\is_int($v) && !(\is_string($v) && ctype_digit($v))) {
                $p->error('wlan_ids', 'Lista de redes inválida.');
                break;
            }
            $wlanIds[(int) $v] = true;
        }
        $wlanIds = array_keys($wlanIds);
        if ([] !== $wlanIds) {
            $found = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM unifi_wlan WHERE present AND id = ANY(CAST(? AS int[]))', ['{'.implode(',', $wlanIds).'}']));
            if (\count($found) !== \count($wlanIds)) {
                $p->error('wlan_ids', 'Uma das redes escolhidas não existe mais no UniFi. Sincronize o controller.');
            }
            $deny = $this->db->fetchOne("SELECT name FROM unifi_wlan WHERE filter_policy = 'deny' AND id = ANY(CAST(? AS int[])) LIMIT 1", ['{'.implode(',', $wlanIds).'}']);
            if (false !== $deny) {
                $p->error('wlan_ids', "A rede \"$deny\" usa filtro de BLOQUEIO no UniFi — o painel só gerencia listas de permissão.");
            }
        }
        if (null === $personName && null === $personId && null === $assetUuid) {
            $p->error('person_name', 'Identifique o MAC: informe a pessoa, um usuário ou o patrimônio.');
        }
        $assetId = null;
        if (null !== $assetUuid) {
            $assetId = $this->db->fetchOne('SELECT id FROM asset WHERE uuid::text = ?', [strtolower($assetUuid)]);
            if (false === $assetId) {
                $p->error('asset_uuid', 'Ativo não encontrado.');
            }
        }
        if (null !== $personId && false === $this->db->fetchOne('SELECT 1 FROM app_user WHERE id = ?', [$personId])) {
            $p->error('person_id', 'Usuário não encontrado.');
        }
        if (null !== $branchId && false === $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ?', [$branchId])) {
            $p->error('branch_id', 'Filial não encontrada.');
        }
        $row = [
            'mac' => $mac, 'person_name' => $personName, 'registration' => $p->string('registration', 40), 'department' => $p->string('department', 100),
            'email' => $p->email('email'), 'phone' => $p->string('phone', 30), 'person_id' => $personId,
            'device_kind' => $kind, 'device_brand' => $p->string('device_brand', 60), 'device_model' => $p->string('device_model', 100), 'hostname' => $p->string('hostname', 100),
            'asset_id' => false === $assetId ? null : $assetId, 'branch_id' => $branchId,
            'valid_until' => null === $validUntil ? null : $validUntil.' 23:59:59 America/Sao_Paulo', 'notes' => $p->string('notes', 2000),
        ];
        $p->check();

        $touched = [];
        $this->db->transactional(function () use (&$id, $row, $wlanIds, $current, &$touched): void {
            $now = date('c');
            if (null === $id) {
                $this->db->insert('mac_identity', $row + ['status' => 'active', 'source' => 'panel', 'created_by' => $this->actor->name(), 'created_at' => $now, 'updated_at' => $now]);
                $id = (int) $this->db->lastInsertId();
            } else {
                // reativar renovando a validade: uma identidade revogada volta a valer se o usuário salvar com redes
                $this->db->update('mac_identity', $row + ['updated_at' => $now] + ([] !== $wlanIds && 'revoked' === $current['status'] ? ['status' => 'active'] : []), ['id' => $id]);
            }
            $touched = $this->syncAssignments($id, $wlanIds);
        });

        return ['id' => (int) $id, 'wlans' => $touched];
    }

    /** @return list<int> redes afetadas */
    public function revoke(int $id): array
    {
        $this->detail($id);
        $wlans = [];
        $this->db->transactional(function () use ($id, &$wlans): void {
            $this->db->executeStatement("UPDATE mac_identity SET status = 'revoked', updated_at = now() WHERE id = ?", [$id]);
            $wlans = $this->markRemoving($id, null);
        });

        return $wlans;
    }

    public function delete(int $id): void
    {
        $i = $this->detail($id);
        if ([] !== $i['wlans']) {
            throw new ValidationException(['id' => 'O MAC ainda está em redes do UniFi. Revogue e aplique antes de excluir (o histórico fica na auditoria).']);
        }
        $this->db->delete('mac_identity', ['id' => $id]);
    }

    /** Vencidos viram revogados e saem das redes. @return list<int> redes afetadas */
    public function expireDue(): array
    {
        $ids = array_map('intval', $this->db->fetchFirstColumn("SELECT id FROM mac_identity WHERE status = 'active' AND valid_until IS NOT NULL AND valid_until <= now()"));
        $wlans = [];
        foreach ($ids as $id) {
            $wlans = array_merge($wlans, $this->revoke($id));
        }

        return array_values(array_unique($wlans));
    }

    /**
     * Ajuda do cadastro: fabricante, se é MAC aleatório, se já existe identidade, e ativos do inventário com esse MAC.
     *
     * @return array<string, mixed>
     */
    public function lookup(string $input): array
    {
        $mac = MacAddress::normalize($input);
        if (null === $mac) {
            throw new ValidationException(['mac' => 'MAC inválido.']);
        }
        $assets = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT DISTINCT a.uuid AS asset_uuid, a.name, a.inventory_number, a.branch_id, b.name AS branch, p.name AS port
            FROM network_port p JOIN asset a ON a.id = p.asset_id LEFT JOIN branch b ON b.id = a.branch_id
            WHERE lower(p.mac_address) = ? ORDER BY a.name LIMIT 10
            SQL, [$mac]);
        $identity = $this->db->fetchAssociative('SELECT id, person_name, status FROM mac_identity WHERE mac = ?', [$mac]);
        $inWlans = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT w.id, w.name, coalesce(s.description, s.site_ref) AS site FROM unifi_wlan w JOIN unifi_site s ON s.id = w.site_id
            WHERE w.present AND jsonb_exists(w.remote_macs, ?) ORDER BY w.name
            SQL, [$mac]);

        return [
            'mac' => $mac, 'vendor' => $this->vendors->vendor(str_replace(':', '', $mac)), 'randomized' => MacAddress::isRandomized($mac),
            'identity' => false === $identity ? null : $identity, 'assets' => $assets, 'in_wlans' => $inWlans,
        ];
    }

    /**
     * MACs que estão no filtro da rede no UniFi mas não têm identidade gerenciada nesta rede.
     *
     * @return list<array<string, mixed>>
     */
    public function unknown(int $wlanId): array
    {
        $remote = $this->db->fetchOne('SELECT remote_macs FROM unifi_wlan WHERE id = ?', [$wlanId]);
        if (false === $remote) {
            throw new NotFoundException('Rede Wi-Fi não encontrada.');
        }
        $managed = array_flip($this->db->fetchFirstColumn('SELECT i.mac FROM mac_wlan_assignment a JOIN mac_identity i ON i.id = a.identity_id WHERE a.wlan_id = ?', [$wlanId]));
        $out = [];
        foreach ((array) json_decode((string) $remote, true) as $mac) {
            if (!\is_string($mac) || isset($managed[$mac])) {
                continue;
            }
            $id = $this->db->fetchAssociative('SELECT id, person_name FROM mac_identity WHERE mac = ?', [$mac]);
            $out[] = ['mac' => $mac, 'vendor' => $this->vendors->vendor(str_replace(':', '', $mac)), 'randomized' => MacAddress::isRandomized($mac),
                'identity' => false === $id ? null : $id];
        }

        return $out;
    }

    /**
     * Identifica um MAC que já estava no UniFi: cria (ou reaproveita) a identidade e liga à rede já como `synced`.
     *
     * @param array<string, mixed> $data
     */
    public function adopt(int $wlanId, array $data): int
    {
        $mac = MacAddress::normalize((string) ($data['mac'] ?? ''));
        $remote = $this->db->fetchOne('SELECT remote_macs FROM unifi_wlan WHERE id = ?', [$wlanId]);
        if (false === $remote) {
            throw new NotFoundException('Rede Wi-Fi não encontrada.');
        }
        if (null === $mac || !\in_array($mac, (array) json_decode((string) $remote, true), true)) {
            throw new ValidationException(['mac' => 'Este MAC não está no filtro desta rede no UniFi (sincronize de novo).']);
        }
        $existing = $this->db->fetchOne('SELECT id FROM mac_identity WHERE mac = ?', [$mac]);

        return $this->db->transactional(function () use ($existing, $data, $mac, $wlanId): int {
            if (false === $existing) {
                $data['mac'] = $mac;
                unset($data['wlan_ids']);
                $id = $this->save(null, $data)['id'];
                $this->db->executeStatement("UPDATE mac_identity SET source = 'imported' WHERE id = ?", [$id]);
            } else {
                $id = (int) $existing;
            }
            $this->db->executeStatement(<<<'SQL'
                INSERT INTO mac_wlan_assignment (identity_id, wlan_id, state, synced_at, created_by, created_at, updated_at) VALUES (?, ?, 'synced', now(), ?, now(), now())
                ON CONFLICT (identity_id, wlan_id) DO UPDATE SET state = 'synced', last_error = NULL, synced_at = now(), updated_at = now()
                SQL, [$id, $wlanId, $this->actor->name()]);

            return $id;
        });
    }

    /** @return array<string, mixed> */
    public function choices(): array
    {
        $map = static fn (array $a): array => array_map(static fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys($a), $a);

        return ['device_kinds' => $map(self::DEVICE_KINDS), 'states' => $map(self::STATES), 'auth_modes' => $map(ControllerService::AUTH)];
    }

    /**
     * @param list<int> $wlanIds
     *
     * @return list<int> redes afetadas
     */
    private function syncAssignments(int $id, array $wlanIds): array
    {
        $existing = array_map('intval', $this->db->fetchFirstColumn('SELECT wlan_id FROM mac_wlan_assignment WHERE identity_id = ?', [$id]));
        $add = array_values(array_diff($wlanIds, $existing));
        foreach ($add as $w) {
            $this->db->insert('mac_wlan_assignment', ['identity_id' => $id, 'wlan_id' => $w, 'state' => 'pending', 'created_by' => $this->actor->name(),
                'created_at' => date('c'), 'updated_at' => date('c')]);
        }
        // voltou a uma rede que estava saindo: continua nela
        $back = array_values(array_intersect($wlanIds, $existing));
        if ([] !== $back) {
            $this->db->executeStatement(
                "UPDATE mac_wlan_assignment SET state = 'pending', updated_at = now() WHERE identity_id = ? AND state = 'removing' AND wlan_id = ANY(CAST(? AS int[]))",
                [$id, '{'.implode(',', $back).'}']
            );
        }
        $removed = $this->markRemoving($id, $wlanIds);

        return array_values(array_unique(array_merge($add, $back, $removed)));
    }

    /**
     * @param list<int>|null $keep redes que continuam (null = sair de todas)
     *
     * @return list<int>
     */
    private function markRemoving(int $id, ?array $keep): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, wlan_id, state, synced_at FROM mac_wlan_assignment WHERE identity_id = ? AND NOT (wlan_id = ANY(CAST(? AS int[])))',
            [$id, '{'.implode(',', $keep ?? []).'}']
        );
        foreach ($rows as $r) {
            if ('pending' === $r['state'] && null === $r['synced_at']) {
                $this->db->delete('mac_wlan_assignment', ['id' => $r['id']]); // nunca chegou ao UniFi
            } else {
                $this->db->executeStatement("UPDATE mac_wlan_assignment SET state = 'removing', updated_at = now() WHERE id = ?", [$r['id']]);
            }
        }

        return array_map(static fn (array $r): int => (int) $r['wlan_id'], $rows);
    }
}
