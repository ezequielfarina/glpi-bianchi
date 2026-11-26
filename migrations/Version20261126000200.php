<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Objetos de banco fora do alcance do ORM + dados de referência.
 *
 *  1. Trigger que mantém `path` (ltree) em organization/location a partir de parent_id,
 *     incluindo reescrita da subárvore quando um nó muda de pai, e bloqueio de ciclos.
 *  2. Índices GiST (ltree) e GIN (jsonb, trigram) — o Doctrine não modela o método de
 *     acesso (USING GIN/GIST). Eles são criados aqui e registrados no schema do ORM por
 *     App\Shared\Doctrine\SchemaExtrasListener, para o `migrations:diff` não tentar removê-los.
 *  3. Seeds: organização "root" e tipos de ativo padrão.
 */
final class Version20261126000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Triggers ltree, índices GiST/GIN e dados de referência (root, asset types)';
    }

    public function up(Schema $schema): void
    {
        // ---------------------------------------------------------------- 1. ltree
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_tree_path() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                parent_path ltree;
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    NEW.path := text2ltree(NEW.id::text);
                ELSE
                    EXECUTE format('SELECT path FROM %I WHERE id = $1', TG_TABLE_NAME)
                        INTO parent_path USING NEW.parent_id;
                    IF parent_path IS NULL THEN
                        RAISE EXCEPTION '%: parent % not found', TG_TABLE_NAME, NEW.parent_id;
                    END IF;
                    IF parent_path ~ ('*.' || NEW.id::text || '.*')::lquery THEN
                        RAISE EXCEPTION '%: cycle detected (% would become descendant of itself)', TG_TABLE_NAME, NEW.id;
                    END IF;
                    NEW.path := parent_path || text2ltree(NEW.id::text);
                END IF;
                RETURN NEW;
            END $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_tree_path_cascade() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.path IS DISTINCT FROM OLD.path THEN
                    EXECUTE format(
                        'UPDATE %I SET path = $1 || subpath(path, nlevel($2)) WHERE path <@ $2 AND id <> $3',
                        TG_TABLE_NAME
                    ) USING NEW.path, OLD.path, NEW.id;
                END IF;
                RETURN NULL;
            END $$
            SQL);

        foreach (['organization', 'location'] as $table) {
            $this->addSql(\sprintf(
                'CREATE TRIGGER trg_%1$s_path BEFORE INSERT OR UPDATE OF parent_id ON %1$s FOR EACH ROW EXECUTE FUNCTION app_tree_path()',
                $table,
            ));
            // pg_trigger_depth() evita recursão: o UPDATE em cascata não dispara nova cascata
            $this->addSql(\sprintf(
                'CREATE TRIGGER trg_%1$s_path_cascade AFTER UPDATE OF parent_id ON %1$s FOR EACH ROW WHEN (pg_trigger_depth() = 0) EXECUTE FUNCTION app_tree_path_cascade()',
                $table,
            ));
            $this->addSql(\sprintf('CREATE INDEX gist_%1$s_path ON %1$s USING GIST (path)', $table));
        }

        // ---------------------------------------------------------------- 2. índices especiais
        $this->addSql('CREATE INDEX gin_asset_attributes ON asset USING GIN (attributes jsonb_path_ops)');
        $this->addSql('CREATE INDEX gin_asset_name_trgm ON asset USING GIN (name gin_trgm_ops)');
        $this->addSql('CREATE INDEX gin_software_name_trgm ON software USING GIN (name gin_trgm_ops)');

        // ---------------------------------------------------------------- 3. seeds
        $this->addSql("INSERT INTO organization (code, name, created_at, updated_at) VALUES ('root', 'Raiz', NOW(), NOW())");

        $types = [
            ['computer', 'Computador', 'ti-device-desktop', true],
            ['monitor', 'Monitor', 'ti-device-desktop-analytics', false],
            ['network_equipment', 'Equipamento de rede', 'ti-network', true],
            ['printer', 'Impressora', 'ti-printer', true],
            ['phone', 'Telefone', 'ti-device-mobile', true],
            ['peripheral', 'Periférico', 'ti-usb', false],
            ['pdu', 'PDU', 'ti-plug', true],
            ['rack', 'Rack', 'ti-server-2', false],
            ['enclosure', 'Chassi / Enclosure', 'ti-box', false],
            ['unmanaged', 'Não gerenciado', 'ti-question-mark', true],
        ];
        foreach ($types as [$code, $label, $icon, $inventoriable]) {
            $this->addSql(
                'INSERT INTO asset_type (code, label, icon, inventoriable) VALUES (?, ?, ?, ?)',
                [$code, $label, $icon, $inventoriable],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Seeds não são removidos aqui: as tabelas são derrubadas pela Version20261126000100::down().
        $this->addSql('DROP INDEX IF EXISTS gin_software_name_trgm');
        $this->addSql('DROP INDEX IF EXISTS gin_asset_name_trgm');
        $this->addSql('DROP INDEX IF EXISTS gin_asset_attributes');
        foreach (['organization', 'location'] as $table) {
            $this->addSql(\sprintf('DROP INDEX IF EXISTS gist_%s_path', $table));
            $this->addSql(\sprintf('DROP TRIGGER IF EXISTS trg_%1$s_path_cascade ON %1$s', $table));
            $this->addSql(\sprintf('DROP TRIGGER IF EXISTS trg_%1$s_path ON %1$s', $table));
        }
        $this->addSql('DROP FUNCTION IF EXISTS app_tree_path_cascade()');
        $this->addSql('DROP FUNCTION IF EXISTS app_tree_path()');
    }
}
