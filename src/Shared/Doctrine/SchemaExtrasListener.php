<?php

declare(strict_types=1);

namespace App\Shared\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Registra no schema "esperado" do ORM os índices criados por SQL manual
 * (migration Version20261126000200): GiST em ltree e GIN em jsonb/trigram.
 *
 * Sem isto, `doctrine:migrations:diff` e `schema:validate` veriam esses índices
 * como sobra e gerariam DROP INDEX. O DBAL não enxerga o método de acesso
 * (USING GIN/GIST), então declarar nome + coluna basta para os schemas baterem.
 *
 * Ao criar um índice especial novo: migration SQL + uma linha em EXTRA_INDEXES.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class SchemaExtrasListener
{
    /** @var array<string, array<string, list<string>>> tabela => [índice => colunas] */
    private const array EXTRA_INDEXES = [
        'organization' => ['gist_organization_path' => ['path']],
        'location' => ['gist_location_path' => ['path']],
        'asset' => [
            'gin_asset_attributes' => ['attributes'],
            'gin_asset_name_trgm' => ['name'],
        ],
        'software' => ['gin_software_name_trgm' => ['name']],
        // ADR-0016: GiST inet_ops para << / >>= no IPAM (migration Version20261128090000)
        'ipam_prefix' => ['gist_ipam_prefix' => ['prefix']],
        'ipam_ip_address' => ['gist_ipam_ip_address' => ['address']],
    ];

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        foreach (self::EXTRA_INDEXES as $tableName => $indexes) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            foreach ($indexes as $indexName => $columns) {
                if (!$table->hasIndex($indexName)) {
                    $table->addIndex($columns, $indexName);
                }
            }
        }
    }
}
