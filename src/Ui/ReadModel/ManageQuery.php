<?php

declare(strict_types=1);

namespace App\Ui\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * Leituras das telas de gestão: cadastros auxiliares, fornecedores, contratos,
 * licenças (com conformidade) e anexos.
 */
final readonly class ManageQuery
{
    /** Status do contrato calculado no SQL (vigente / vencendo / vencido / sem prazo). */
    private const string CONTRACT_STATUS = <<<'SQL'
        CASE
          WHEN c.end_date IS NULL THEN 'open'
          WHEN c.end_date < current_date THEN 'expired'
          WHEN c.end_date <= current_date + make_interval(days => coalesce(c.notice_days, 30)) THEN 'expiring'
          ELSE 'active'
        END
        SQL;

    /** Valor anualizado do contrato (para somatórios). */
    private const string CONTRACT_ANNUAL = <<<'SQL'
        c.value * CASE c.billing_period WHEN 'monthly' THEN 12 WHEN 'quarterly' THEN 4 WHEN 'semiannual' THEN 2 WHEN 'yearly' THEN 1 ELSE NULL END
        SQL;

    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function lookups(): array
    {
        return [
            'asset_types' => $this->db->fetchAllAssociative('SELECT code, label, inventoriable FROM asset_type ORDER BY label'),
            'license_types' => $this->db->fetchAllAssociative('SELECT t.id, t.name, t.assignment, (SELECT count(*) FROM license WHERE license_type_id = t.id) AS in_use FROM license_type t ORDER BY t.name'),
            'license_categories' => $this->db->fetchAllAssociative('SELECT g.id, g.name, (SELECT count(*) FROM license WHERE license_category_id = g.id) AS in_use FROM license_category g ORDER BY g.name'),
            'contract_types' => $this->db->fetchAllAssociative('SELECT t.id, t.name, (SELECT count(*) FROM contract WHERE contract_type_id = t.id) AS in_use FROM contract_type t ORDER BY t.name'),
            'suppliers' => $this->db->fetchAllAssociative('SELECT id, name FROM supplier WHERE active ORDER BY name'),
            'contracts' => $this->db->fetchAllAssociative('SELECT id, title, number FROM contract ORDER BY title'),
            'locations' => $this->db->fetchAllAssociative('SELECT id, name FROM location ORDER BY name'),
            'branches' => $this->db->fetchAllAssociative("SELECT id, name, code, kind FROM branch WHERE active ORDER BY kind = 'headquarters' DESC, name"),
            'manufacturers' => $this->db->fetchAllAssociative('SELECT id, name FROM manufacturer ORDER BY name LIMIT 500'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function suppliers(?string $q): array
    {
        $params = [];
        $where = '';
        if (null !== $q && '' !== trim($q)) {
            $where = 'WHERE s.name ILIKE :q OR s.trade_name ILIKE :q OR s.tax_id LIKE :d OR s.email ILIKE :q';
            $params = ['q' => '%'.trim($q).'%', 'd' => '%'.preg_replace('/\D+/', '', $q).'%'];
        }

        return $this->db->fetchAllAssociative(<<<SQL
            SELECT s.id, s.name, s.trade_name, s.tax_id, s.email, s.phone, s.website, s.active,
                   (SELECT count(*) FROM contact WHERE supplier_id = s.id) AS contacts,
                   (SELECT count(*) FROM contract WHERE supplier_id = s.id) AS contracts,
                   (SELECT count(*) FROM license WHERE supplier_id = s.id) AS licenses
            FROM supplier s $where ORDER BY s.active DESC, s.name
            SQL, $params);
    }

    /** @return array<string, mixed>|null */
    public function supplier(int $id): ?array
    {
        $s = $this->db->fetchAssociative('SELECT * FROM supplier WHERE id = ?', [$id]);
        if (false === $s) {
            return null;
        }

        return $s + [
            'contacts_list' => $this->db->fetchAllAssociative('SELECT id, name, role, email, phone, mobile, notes FROM contact WHERE supplier_id = ? ORDER BY name', [$id]),
            'contracts_list' => $this->contracts(null, $id),
            'licenses_list' => $this->db->fetchAllAssociative('SELECT id, name, seats, expires_at FROM license WHERE supplier_id = ? ORDER BY name', [$id]),
            'attachments' => $this->attachments('supplier', $id),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function contracts(?string $status, ?int $supplierId = null): array
    {
        $where = [];
        $params = [];
        if (null !== $status && '' !== $status) {
            $where[] = '('.self::CONTRACT_STATUS.') = :status';
            $params['status'] = $status;
        }
        if (null !== $supplierId) {
            $where[] = 'c.supplier_id = :supplier';
            $params['supplier'] = $supplierId;
        }
        $whereSql = [] === $where ? '' : 'WHERE '.implode(' AND ', $where);

        return $this->db->fetchAllAssociative(\sprintf(<<<'SQL'
            SELECT c.id, c.title, c.number, c.start_date, c.end_date, c.renewal, c.notice_days, c.billing_period, c.value,
                   %s AS status, %s AS annual_value,
                   (c.end_date - current_date) AS days_left,
                   s.id AS supplier_id, s.name AS supplier, t.name AS contract_type,
                   (SELECT count(*) FROM contract_asset WHERE contract_id = c.id) AS assets,
                   (SELECT count(*) FROM attachment WHERE owner_type = 'contract' AND owner_id = c.id) AS attachments
            FROM contract c
            LEFT JOIN supplier s ON s.id = c.supplier_id
            LEFT JOIN contract_type t ON t.id = c.contract_type_id
            %s
            ORDER BY c.end_date NULLS LAST, c.title
            SQL, self::CONTRACT_STATUS, self::CONTRACT_ANNUAL, $whereSql), $params);
    }

    /** @return array<string, mixed>|null */
    public function contract(int $id): ?array
    {
        $c = $this->db->fetchAssociative(\sprintf(<<<'SQL'
            SELECT c.*, %s AS status, %s AS annual_value, (c.end_date - current_date) AS days_left,
                   s.name AS supplier, t.name AS contract_type
            FROM contract c LEFT JOIN supplier s ON s.id = c.supplier_id LEFT JOIN contract_type t ON t.id = c.contract_type_id
            WHERE c.id = ?
            SQL, self::CONTRACT_STATUS, self::CONTRACT_ANNUAL), [$id]);
        if (false === $c) {
            return null;
        }

        return $c + [
            'assets' => $this->db->fetchAllAssociative(<<<'SQL'
                SELECT a.uuid, a.name, a.inventory_number, a.serial_number, t.label AS type_label
                FROM contract_asset ca JOIN asset a ON a.id = ca.asset_id JOIN asset_type t ON t.id = a.asset_type_id
                WHERE ca.contract_id = ? ORDER BY a.name
                SQL, [$id]),
            'licenses' => $this->db->fetchAllAssociative('SELECT id, name, seats FROM license WHERE contract_id = ? ORDER BY name', [$id]),
            'attachments' => $this->attachments('contract', $id),
        ];
    }

    /**
     * Licenças com conformidade:
     *   used     = assentos atribuídos
     *   detected = ativos com software que casa com software_match (agente)
     *   status   = over (usados ou detectados > comprados) | expired | expiring | ok
     *
     * @return list<array<string, mixed>>
     */
    public function licenses(?string $q, ?int $categoryId): array
    {
        $where = [];
        $params = [];
        if (null !== $q && '' !== trim($q)) {
            $where[] = '(l.name ILIKE :q OR l.software_match ILIKE :q)';
            $params['q'] = '%'.trim($q).'%';
        }
        if (null !== $categoryId) {
            $where[] = 'l.license_category_id = :cat';
            $params['cat'] = $categoryId;
        }
        $whereSql = [] === $where ? '' : 'WHERE '.implode(' AND ', $where);

        return $this->db->fetchAllAssociative(<<<SQL
            SELECT x.*,
                   CASE WHEN x.used > x.seats OR x.detected > x.seats THEN 'over'
                        WHEN x.expires_at < current_date THEN 'expired'
                        WHEN x.expires_at <= current_date + 30 THEN 'expiring'
                        ELSE 'ok' END AS compliance
            FROM (
              SELECT l.id, l.name, l.assignment, l.seats, l.unit_price, (l.unit_price * l.seats) AS total_price,
                     l.purchase_date, l.expires_at, l.software_match, (l.license_key IS NOT NULL) AS has_key,
                     t.name AS license_type, g.name AS category, s.name AS supplier, c.title AS contract,
                     (SELECT count(*) FROM license_assignment WHERE license_id = l.id) AS used,
                     CASE WHEN l.software_match IS NULL THEN NULL ELSE (
                       SELECT count(DISTINCT si.asset_id) FROM software_installation si
                       JOIN software_version sv ON sv.id = si.software_version_id
                       JOIN software sw ON sw.id = sv.software_id
                       JOIN asset a ON a.id = si.asset_id AND a.deleted_at IS NULL
                       WHERE sw.name ILIKE '%' || l.software_match || '%') END AS detected
              FROM license l
              JOIN license_type t ON t.id = l.license_type_id
              LEFT JOIN license_category g ON g.id = l.license_category_id
              LEFT JOIN supplier s ON s.id = l.supplier_id
              LEFT JOIN contract c ON c.id = l.contract_id
              $whereSql
            ) x
            ORDER BY x.name
            SQL, $params);
    }

    /** @return array<string, mixed>|null */
    public function license(int $id): ?array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id FROM license WHERE id = ?', [$id]);
        if ([] === $rows) {
            return null;
        }
        $summary = array_values(array_filter($this->licenses(null, null), static fn (array $l): bool => (int) $l['id'] === $id))[0] ?? [];
        $base = $this->db->fetchAssociative('SELECT license_type_id, license_category_id, supplier_id, contract_id, notes, created_at, updated_at FROM license WHERE id = ?', [$id]) ?: [];
        $match = $summary['software_match'] ?? null;

        return $summary + $base + [
            'assignments' => $this->db->fetchAllAssociative(<<<'SQL'
                SELECT la.id, la.assigned_at, la.notes,
                       a.uuid AS asset_uuid, a.name AS asset_name, a.inventory_number,
                       u.username AS user, u.display_name AS user_name
                FROM license_assignment la
                LEFT JOIN asset a ON a.id = la.asset_id
                LEFT JOIN app_user u ON u.id = la.user_id
                WHERE la.license_id = ? ORDER BY coalesce(a.name, u.username)
                SQL, [$id]),
            // Instalações detectadas pelo agente que ainda NÃO têm assento atribuído
            'unassigned_detected' => null === $match ? [] : $this->db->fetchAllAssociative(<<<'SQL'
                SELECT DISTINCT a.uuid, a.name, a.inventory_number, sw.name AS software, sv.version
                FROM software_installation si
                JOIN software_version sv ON sv.id = si.software_version_id
                JOIN software sw ON sw.id = sv.software_id
                JOIN asset a ON a.id = si.asset_id AND a.deleted_at IS NULL
                WHERE sw.name ILIKE '%' || ? || '%'
                  AND NOT EXISTS (SELECT 1 FROM license_assignment la WHERE la.license_id = ? AND la.asset_id = a.id)
                ORDER BY a.name LIMIT 500
                SQL, [$match, $id]),
            'attachments' => $this->attachments('license', $id),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function attachments(string $ownerType, int $ownerId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT uuid, kind, filename, mime_type, size_bytes, description, created_by, created_at FROM attachment WHERE owner_type = ? AND owner_id = ? ORDER BY kind DESC, created_at DESC',
            [$ownerType, $ownerId],
        );
    }

    // ------------------------------------------------------------------ matriz e filiais

    private const string BRANCH_COLUMNS = <<<'SQL'
        b.id, b.name, b.code, b.kind, b.tax_id, b.phone, b.email, b.zip_code, b.street, b.number, b.complement,
        b.district, b.city, b.state, b.latitude::float8 AS latitude, b.longitude::float8 AS longitude,
        b.networks, b.notes, b.active, b.created_at, b.updated_at,
        (SELECT count(*) FROM asset a WHERE a.branch_id = b.id AND a.deleted_at IS NULL) AS assets,
        (SELECT count(*) FROM asset a JOIN asset_type t ON t.id = a.asset_type_id
          WHERE a.branch_id = b.id AND a.deleted_at IS NULL AND t.code = 'computer') AS computers,
        (SELECT count(*) FROM asset a WHERE a.branch_id = b.id AND a.deleted_at IS NULL
          AND a.dynamic AND a.last_inventory_at < now() - interval '7 days') AS stale
        SQL;

    /** @return list<array<string, mixed>> */
    public function branches(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT '.self::BRANCH_COLUMNS." FROM branch b ORDER BY b.active DESC, b.kind = 'headquarters' DESC, b.name",
        );

        return array_map(self::decodeBranch(...), $rows);
    }

    /** @return array<string, mixed>|null */
    public function branch(int $id): ?array
    {
        $b = $this->db->fetchAssociative('SELECT '.self::BRANCH_COLUMNS.' FROM branch b WHERE b.id = ?', [$id]);
        if (false === $b) {
            return null;
        }

        return self::decodeBranch($b) + [
            'by_type' => $this->db->fetchAllAssociative(<<<'SQL'
                SELECT t.code AS type, t.label AS type_label, count(*) AS total
                FROM asset a JOIN asset_type t ON t.id = a.asset_type_id
                WHERE a.branch_id = ? AND a.deleted_at IS NULL
                GROUP BY t.code, t.label ORDER BY total DESC, t.label
                SQL, [$id]),
            'unassigned_assets' => (int) $this->db->fetchOne('SELECT count(*) FROM asset WHERE branch_id IS NULL AND deleted_at IS NULL'),
            'attachments' => $this->attachments('branch', $id),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decodeBranch(array $row): array
    {
        $row['networks'] = json_decode((string) $row['networks'], true) ?: [];
        foreach (['assets', 'computers', 'stale'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        foreach (['latitude', 'longitude'] as $k) {
            $row[$k] = null === $row[$k] ? null : (float) $row[$k];
        }

        return $row;
    }

    /** Resumo para a visão geral: contratos vencendo, licenças em risco. @return array<string, int|string|null> */
    public function alerts(): array
    {
        return $this->db->fetchAssociative(\sprintf(<<<'SQL'
            SELECT
              (SELECT count(*) FROM contract c WHERE (%1$s) = 'expiring') AS contracts_expiring,
              (SELECT count(*) FROM contract c WHERE (%1$s) = 'expired')  AS contracts_expired,
              (SELECT coalesce(sum(%2$s), 0) FROM contract c WHERE (%1$s) IN ('active', 'expiring', 'open')) AS contracts_annual_value,
              (SELECT count(*) FROM license l WHERE l.expires_at BETWEEN current_date AND current_date + 30) AS licenses_expiring,
              (SELECT count(*) FROM license l WHERE (SELECT count(*) FROM license_assignment WHERE license_id = l.id) > l.seats) AS licenses_over
            SQL, self::CONTRACT_STATUS, self::CONTRACT_ANNUAL)) ?: [];
    }
}
