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
     * @param bool                                                                          $pii e-mail e telefone completos (técnico/admin); perfil Leitura vê mascarado — LGPD, necessidade
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $f = [], bool $pii = true): array
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
            if (!$pii) {
                $r['email'] = self::mask($r['email']);
                $r['phone'] = self::mask($r['phone']);
            }
        }
        unset($r);

        return $rows;
    }

    /** @return array<string, mixed> */
    public function detail(int $id, bool $pii = true): array
    {
        return $this->list(['id' => $id], $pii)[0] ?? throw new NotFoundException('Identidade de MAC não encontrada.');
    }

    /** Tira caracteres de controle/invisíveis (colados de planilha/WhatsApp) e espaços repetidos. */
    private static function text(?string $v, bool $multiline = false): ?string
    {
        if (null === $v) {
            return null;
        }
        $v = (string) preg_replace($multiline ? '/[^\P{Cc}\n\t]|\p{Cf}/u' : '/[\p{Cc}\p{Cf}]/u', $multiline ? '' : ' ', $v);
        $v = trim((string) preg_replace($multiline ? '/[ \t]+/' : '/\s+/u', ' ', $v));

        return '' === $v ? null : $v;
    }

    private static function mask(mixed $v): ?string
    {
        if (!\is_string($v) || '' === $v) {
            return null;
        }
        if (str_contains($v, '@')) {
            [$user, $domain] = explode('@', $v, 2);

            return mb_substr($user, 0, 1).'•••@'.$domain;
        }

        return '••••'.mb_substr((string) preg_replace('/\D/', '', $v), -4);
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
        $other = null === $mac ? false : $this->db->fetchAssociative('SELECT coalesce(person_name, mac) AS who, status FROM mac_identity WHERE mac = ? AND id <> ?', [$mac, $id ?? 0]);
        if (null === $mac) {
            $p->error('mac', 'MAC inválido. Aceita AA:BB:CC:DD:EE:FF, AA-BB-…, AABB.CCDD.EEFF ou só os 12 dígitos.');
        } elseif (MacAddress::isMulticast($mac)) {
            $p->error('mac', 'Endereço multicast — não é MAC de aparelho.');
        } elseif (false !== $other) {
            $p->error('mac', 'revoked' === $other['status']
                ? 'Este MAC já tem cadastro revogado ('.$other['who'].'): abra esse cadastro e salve com as redes para reativar.'
                : 'Este MAC já está cadastrado ('.$other['who'].').');
        } elseif (MacAddress::isRandomized($mac) && ($current['mac'] ?? null) !== $mac && !$p->bool('confirm_random')) {
            $p->error('mac', 'MAC aleatório/privado: o aparelho usa outro endereço em cada rede e pode trocar sozinho. Desligue o "endereço privado" para esta rede no aparelho e confirme.');
        }
        if (null !== $current && $current['mac'] !== $mac && [] !== $current['wlans']) {
            $p->error('mac', 'Não dá para trocar o MAC de uma identidade que já está em redes: revogue e cadastre o novo.');
        }
        $personName = self::text($p->string('person_name', 120));
        $personId = $p->int('person_id', 1);
        $assetUuid = $p->string('asset_uuid', 36);
        $branchId = $p->int('branch_id', 1);
        $validUntil = $p->date('valid_until');
        if (null !== $validUntil && $validUntil < date('Y-m-d')) {
            $p->error('valid_until', 'A validade já passou.');
        } elseif (null !== $validUntil && $validUntil > date('Y-m-d', strtotime('+5 years'))) {
            $p->error('valid_until', 'Validade máxima: 5 anos. Para acesso permanente deixe em branco.');
        }
        $phone = $p->string('phone', 30);
        if (null !== $phone && 1 !== preg_match('/^[0-9 ()+\-.]{6,30}$/', $phone)) {
            $p->error('phone', 'Telefone/ramal: só números, espaço, ( ) + - .');
        }
        $registration = $p->string('registration', 40);
        if (null !== $registration && 1 !== preg_match('/^[\p{L}0-9.\-\/]{1,40}$/u', $registration)) {
            $p->error('registration', 'Matrícula: letras, números, ponto, hífen ou barra.');
        }
        $hostname = $p->string('hostname', 100);
        if (null !== $hostname && 1 !== preg_match('/^[A-Za-z0-9._\-]{1,100}$/', $hostname)) {
            $p->error('hostname', 'Nome na rede: letras sem acento, números, ponto, hífen ou sublinhado.');
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
        if (null === $id && [] === $wlanIds && !$p->bool('no_wlan')) {
            $p->error('wlan_ids', 'Escolha ao menos uma rede Wi-Fi onde liberar o aparelho.');
        }
        if (null !== $mac && [] !== $wlanIds) {
            $full = $this->db->fetchOne(<<<'SQL'
                SELECT w.name FROM unifi_wlan w
                WHERE w.id = ANY(CAST(? AS int[])) AND NOT jsonb_exists(w.remote_macs, ?)
                  AND NOT EXISTS (SELECT 1 FROM mac_wlan_assignment x WHERE x.wlan_id = w.id AND x.identity_id = ?)
                  AND jsonb_array_length(w.remote_macs) + (SELECT count(*) FROM mac_wlan_assignment x JOIN mac_identity i ON i.id = x.identity_id
                        WHERE x.wlan_id = w.id AND x.state = 'pending' AND NOT jsonb_exists(w.remote_macs, i.mac)) >= ?
                LIMIT 1
                SQL, ['{'.implode(',', $wlanIds).'}', $mac, $id ?? 0, WlanApplier::MAX_MACS]);
            if (false !== $full) {
                $p->error('wlan_ids', \sprintf('A rede "%s" já tem %d MACs, o máximo do UniFi. Revogue aparelhos antigos dela antes.', $full, WlanApplier::MAX_MACS));
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
            'mac' => $mac, 'person_name' => $personName, 'registration' => $registration, 'department' => self::text($p->string('department', 100)),
            'email' => $p->email('email'), 'phone' => $phone, 'person_id' => $personId,
            'device_kind' => $kind, 'device_brand' => self::text($p->string('device_brand', 60)), 'device_model' => self::text($p->string('device_model', 100)), 'hostname' => $hostname,
            'asset_id' => false === $assetId ? null : $assetId, 'branch_id' => $branchId,
            'valid_until' => null === $validUntil ? null : $validUntil.' 23:59:59 America/Sao_Paulo', 'notes' => self::text($p->string('notes', 2000), true),
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
        // onde o MAC está liberado no UniFi (última sincronização) e se lá ele já tem identidade gerenciada
        $inWlans = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'site_id' => (int) $r['site_id'], 'identified' => (bool) $r['identified'],
            'filter_enabled' => (bool) $r['filter_enabled'], 'deny' => 'deny' === $r['filter_policy']] + $r, $this->db->fetchAllAssociative(<<<'SQL'
                SELECT w.id, w.name, s.id AS site_id, coalesce(s.description, s.site_ref) AS site, c.name AS controller, w.filter_enabled, w.filter_policy, w.synced_at,
                       EXISTS (SELECT 1 FROM mac_wlan_assignment a JOIN mac_identity i ON i.id = a.identity_id WHERE a.wlan_id = w.id AND i.mac = :mac) AS identified
                FROM unifi_wlan w JOIN unifi_site s ON s.id = w.site_id AND s.present JOIN unifi_controller c ON c.id = s.controller_id
                WHERE w.present AND jsonb_exists(w.remote_macs, :mac)
                ORDER BY c.name, site, w.name
                SQL, ['mac' => $mac]));

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
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT m.mac, i.id, i.person_name, i.status
            FROM jsonb_array_elements_text(CAST(? AS jsonb)) m(mac)
            LEFT JOIN mac_identity i ON i.mac = m.mac
            WHERE NOT EXISTS (SELECT 1 FROM mac_wlan_assignment a JOIN mac_identity x ON x.id = a.identity_id WHERE a.wlan_id = ? AND x.mac = m.mac)
            ORDER BY m.mac
            SQL, [$remote, $wlanId]);

        return array_map(fn (array $r): array => [
            'mac' => $r['mac'], 'vendor' => $this->vendors->vendor(str_replace(':', '', (string) $r['mac'])), 'randomized' => MacAddress::isRandomized((string) $r['mac']),
            'identity' => null === $r['id'] ? null : ['id' => (int) $r['id'], 'person_name' => $r['person_name'], 'status' => $r['status']],
        ], $rows);
    }

    /**
     * Identifica um MAC que já estava no UniFi: cria (ou reaproveita) a identidade e liga à rede já como `synced`.
     *
     * @param array<string, mixed> $data
     */
    public function adopt(int $wlanId, array $data): int
    {
        $mac = MacAddress::normalize((string) ($data['mac'] ?? ''));
        $w = $this->db->fetchAssociative('SELECT w.remote_macs, s.controller_id FROM unifi_wlan w JOIN unifi_site s ON s.id = w.site_id WHERE w.id = ?', [$wlanId]);
        if (false === $w) {
            throw new NotFoundException('Rede Wi-Fi não encontrada.');
        }
        if (null === $mac || !\in_array($mac, (array) json_decode((string) $w['remote_macs'], true), true)) {
            throw new ValidationException(['mac' => 'Este MAC não está no filtro desta rede no UniFi (sincronize de novo).']);
        }
        $existing = $this->db->fetchAssociative('SELECT id, status, valid_until FROM mac_identity WHERE mac = ?', [$mac]);

        return $this->db->transactional(function () use ($existing, $data, $mac, $wlanId, $w): int {
            if (false === $existing) {
                $data['mac'] = $mac;
                $data['confirm_random'] = true; // já está no UniFi: identificar não muda o acesso
                $data['no_wlan'] = true;
                unset($data['wlan_ids'], $data['apply']);
                $id = $this->save(null, $data)['id'];
                $this->db->executeStatement("UPDATE mac_identity SET source = 'imported' WHERE id = ?", [$id]);
            } else {
                $id = (int) $existing['id'];
                // revogado/vencido e ainda no UniFi: identificar = manter o acesso (senão a próxima aplicação o retiraria)
                $this->db->executeStatement(
                    "UPDATE mac_identity SET status = 'active', valid_until = CASE WHEN valid_until <= now() THEN NULL ELSE valid_until END, updated_at = now() WHERE id = ?",
                    [$id]
                );
            }
            // o mesmo aparelho costuma estar em várias redes do controller (Mobile + CelGestores): liga em todas onde já está
            $wlans = array_map('intval', $this->db->fetchFirstColumn(<<<'SQL'
                SELECT w.id FROM unifi_wlan w JOIN unifi_site s ON s.id = w.site_id
                WHERE s.controller_id = ? AND w.present AND jsonb_exists(w.remote_macs, ?)
                SQL, [$w['controller_id'], $mac]));
            foreach (array_unique([$wlanId, ...(false === ($data['all_wlans'] ?? true) ? [] : $wlans)]) as $wl) {
                $this->db->executeStatement(<<<'SQL'
                    INSERT INTO mac_wlan_assignment (identity_id, wlan_id, state, synced_at, created_by, created_at, updated_at) VALUES (?, ?, 'synced', now(), ?, now(), now())
                    ON CONFLICT (identity_id, wlan_id) DO UPDATE SET state = 'synced', last_error = NULL, synced_at = now(), updated_at = now()
                    SQL, [$id, $wl, $this->actor->name()]);
            }

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
