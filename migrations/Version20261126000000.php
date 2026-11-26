<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extensões PostgreSQL exigidas pelo schema.
 *
 *  - ltree   → hierarquias (organization.path, location.path)
 *  - pg_trgm → busca "contém" rápida em nomes (índices GIN trigram, migration 000200)
 *
 * Ambas são "trusted" desde o PG 13: o owner do banco consegue criá-las sem superuser.
 */
final class Version20261126000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extensões PostgreSQL: ltree, pg_trgm';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS ltree');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(Schema $schema): void
    {
        // Intencionalmente vazio: remover extensões pode quebrar outros schemas do mesmo banco.
    }
}
