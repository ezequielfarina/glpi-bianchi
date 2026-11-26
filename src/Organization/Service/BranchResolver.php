<?php

declare(strict_types=1);

namespace App\Organization\Service;

use Doctrine\DBAL\Connection;

/**
 * Atribui filial a ativos **sem filial** pela rede do IP (ADR-0010).
 *
 * IPs considerados: IPv4 das portas físicas do ativo (sem APIPA) e, na falta,
 * o IP de origem do último contato do agente. Entre redes sobrepostas vence a
 * de prefixo mais longo (ex.: /26 da filial ganha do /16 corporativo).
 * Nunca sobrescreve uma filial já definida — escolha manual sempre prevalece.
 */
final readonly class BranchResolver
{
    private const string SQL = <<<'SQL'
        WITH candidate AS (
            SELECT a.id AS asset_id, ip.addr
            FROM asset a
            CROSS JOIN LATERAL (
                SELECT i.address::inet AS addr
                FROM network_port p JOIN ip_address i ON i.network_port_id = p.id
                WHERE p.asset_id = a.id AND i.version = 4 AND NOT p.virtual
                  AND i.address ~ '^[0-9]{1,3}(\.[0-9]{1,3}){3}$' AND i.address NOT LIKE '169.254.%'
                UNION ALL
                SELECT ag.remote_ip::inet
                FROM agent ag
                WHERE ag.asset_id = a.id AND ag.remote_ip ~ '^[0-9]{1,3}(\.[0-9]{1,3}){3}$'
            ) ip
            WHERE a.branch_id IS NULL AND a.deleted_at IS NULL /*FILTER*/
        ),
        best AS (
            SELECT DISTINCT ON (c.asset_id) c.asset_id, b.id AS branch_id
            FROM candidate c
            JOIN branch b ON b.active
            CROSS JOIN LATERAL jsonb_array_elements_text(b.networks) AS n(cidr)
            WHERE c.addr <<= n.cidr::cidr
            ORDER BY c.asset_id, masklen(n.cidr::cidr) DESC, b.id
        )
        UPDATE asset a SET branch_id = best.branch_id, updated_at = now()
        FROM best WHERE a.id = best.asset_id
        RETURNING a.id, best.branch_id
        SQL;

    public function __construct(private Connection $db)
    {
    }

    /**
     * @return list<array{id: int, branch_id: int}> ativos atualizados
     */
    public function assignMissing(?int $assetId = null): array
    {
        $rows = null === $assetId
            ? $this->db->fetchAllAssociative(self::SQL)
            : $this->db->fetchAllAssociative(str_replace('/*FILTER*/', 'AND a.id = :id', self::SQL), ['id' => $assetId]);

        foreach ($rows as $r) {
            $this->db->executeStatement(
                "INSERT INTO asset_history (asset_id, field, old_value, new_value, source, actor, created_at)
                 SELECT ?, 'branch', NULL, name, 'inventory', 'rede da filial', now() FROM branch WHERE id = ?",
                [$r['id'], $r['branch_id']],
            );
        }

        /** @var list<array{id: int, branch_id: int}> */
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'branch_id' => (int) $r['branch_id']], $rows);
    }
}
