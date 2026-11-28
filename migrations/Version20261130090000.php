<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0019: criptografia em repouso.
 * - attachment.encryption_key: chave do arquivo (aleatória) cifrada pela chave mestra (envelope);
 * - raw_inventory: payload vira `payload_sealed` (TEXT cifrado); o JSONB antigo fica em `payload_legacy`
 *   até `bin/console app:crypto:rotate` cifrar e esvaziar (a conversão exige a chave, que o SQL não tem).
 */
final class Version20261130090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Encryption at rest: attachment file keys and sealed raw inventory payload (ADR-0019)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attachment ADD encryption_key TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE raw_inventory RENAME COLUMN payload TO payload_legacy');
        $this->addSql('ALTER TABLE raw_inventory ALTER payload_legacy DROP NOT NULL');
        $this->addSql('ALTER TABLE raw_inventory ADD payload_sealed TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT count(*) FROM raw_inventory WHERE payload_sealed IS NOT NULL') > 0
            || (int) $this->connection->fetchOne('SELECT count(*) FROM attachment WHERE encryption_key IS NOT NULL') > 0,
            'Há dados cifrados (payload_sealed / anexos): voltar perderia o acesso a eles. Decifre antes ou restaure o backup.',
        );
        $this->addSql('ALTER TABLE raw_inventory DROP payload_sealed');
        $this->addSql("UPDATE raw_inventory SET payload_legacy = '{}' WHERE payload_legacy IS NULL");
        $this->addSql('ALTER TABLE raw_inventory ALTER payload_legacy SET NOT NULL');
        $this->addSql('ALTER TABLE raw_inventory RENAME COLUMN payload_legacy TO payload');
        $this->addSql('ALTER TABLE attachment DROP encryption_key');
    }
}
