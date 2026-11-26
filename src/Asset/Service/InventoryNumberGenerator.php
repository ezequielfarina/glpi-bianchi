<?php

declare(strict_types=1);

namespace App\Asset\Service;

use App\Settings\Service\Settings;
use Doctrine\DBAL\Connection;

/**
 * Número de patrimônio sequencial automático: <prefixo><6 dígitos> (ex.: BIA-000123).
 *
 * Sequência PostgreSQL `asset_inventory_seq` (sem lacunas por concorrência, à prova de
 * corrida entre requests/workers). Números digitados à mão são respeitados: se o próximo
 * da sequência já estiver em uso, avança até achar um livre.
 */
final readonly class InventoryNumberGenerator
{
    public function __construct(
        private Connection $db,
        private Settings $settings,
    ) {
    }

    public function next(): string
    {
        $prefix = (string) $this->settings->get(Settings::ASSET_INVENTORY_PREFIX);
        for ($i = 0; $i < 1000; ++$i) {
            $n = (int) $this->db->fetchOne("SELECT nextval('asset_inventory_seq')");
            $candidate = $prefix.str_pad((string) $n, 6, '0', \STR_PAD_LEFT);
            if (false === $this->db->fetchOne('SELECT 1 FROM asset WHERE inventory_number = ?', [$candidate])) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Não foi possível gerar número de patrimônio livre.');
    }

    /** Prévia do próximo número (sem consumir a sequência) — exibida no formulário. */
    public function preview(): string
    {
        $prefix = (string) $this->settings->get(Settings::ASSET_INVENTORY_PREFIX);
        $start = (int) $this->db->fetchOne('SELECT last_value + CASE WHEN is_called THEN 1 ELSE 0 END FROM asset_inventory_seq');
        // mesmo critério do next(): pula números já usados (digitados à mão / importados)
        $free = $this->db->fetchOne(<<<'SQL'
            SELECT n FROM generate_series(?::bigint, ?::bigint + 1000) AS n
            WHERE NOT EXISTS (SELECT 1 FROM asset WHERE inventory_number = ? || lpad(n::text, 6, '0'))
            ORDER BY n LIMIT 1
            SQL, [$start, $start, $prefix]);

        return $prefix.str_pad((string) (false === $free ? $start : $free), 6, '0', \STR_PAD_LEFT);
    }
}
