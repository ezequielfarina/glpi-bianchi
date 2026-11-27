<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0014 (revisão): a nota termina em "enviada para lançamento" — quem lança é outro setor.
 * Notas já marcadas como lançadas viram "enviadas" (mantendo data/autor do envio).
 */
final class Version20261127140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoice status ends at sent: drop posted status and columns (ADR-0014)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE invoice SET status = 'sent', sent_at = coalesce(sent_at, posted_at), sent_by = coalesce(sent_by, posted_by) WHERE status = 'posted'");
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT chk_invoice_status');
        $this->addSql("ALTER TABLE invoice ADD CONSTRAINT chk_invoice_status CHECK (status IN ('registered', 'sent'))");
        $this->addSql('ALTER TABLE invoice DROP COLUMN posted_at');
        $this->addSql('ALTER TABLE invoice DROP COLUMN posted_by');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD posted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD posted_by VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT chk_invoice_status');
        $this->addSql("ALTER TABLE invoice ADD CONSTRAINT chk_invoice_status CHECK (status IN ('registered', 'sent', 'posted'))");
    }
}
