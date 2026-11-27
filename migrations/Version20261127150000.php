<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ADR-0014 (revisão): setor de destino na saída para filial — vai para a etiqueta de envio. */
final class Version20261127150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stock movement destination department for the shipping label (ADR-0014)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement ADD department VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP COLUMN department');
    }
}
