<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * - raw_inventory.outcome / changes: resultado da importação (novo ativo, atualizado,
 *   sem alteração) — exibido em "Inventários recebidos".
 * - app_setting: configurações editáveis pelo painel (frequência de inventário,
 *   porta HTTP do agente).
 */
final class Version20261126160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'raw_inventory.outcome/changes e tabela app_setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE raw_inventory ADD outcome VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE raw_inventory ADD changes INT DEFAULT NULL');
        $this->addSql('CREATE TABLE app_setting (name VARCHAR(64) NOT NULL, value JSONB NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (name))');

        // Backfill: 1º inventário processado de cada ativo = created; demais = updated
        // (quantidade de alterações dos antigos é desconhecida → NULL)
        $this->addSql(<<<'SQL'
            UPDATE raw_inventory r
               SET outcome = CASE WHEN r.id = f.first_id THEN 'created' ELSE 'updated' END
              FROM (SELECT asset_id, min(id) AS first_id FROM raw_inventory
                     WHERE status = 'processed' AND asset_id IS NOT NULL GROUP BY asset_id) f
             WHERE r.asset_id = f.asset_id AND r.status = 'processed'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_setting');
        $this->addSql('ALTER TABLE raw_inventory DROP changes');
        $this->addSql('ALTER TABLE raw_inventory DROP outcome');
    }
}
