<?php

declare(strict_types=1);

namespace App\Ui\Write;

use App\Asset\Entity\Manufacturer;
use Doctrine\DBAL\Connection;

/**
 * Resolve referências digitadas na interface (texto livre) para IDs, criando o
 * cadastro auxiliar quando não existe: fabricante, modelo, localização, usuário.
 * Assim o formulário aceita "Epson" / "Sala TI" / "joao.silva" sem telas extras.
 */
final readonly class Refs
{
    public function __construct(private Connection $db)
    {
    }

    public function rootOrganizationId(): int
    {
        return (int) $this->db->fetchOne("SELECT id FROM organization WHERE code = 'root'");
    }

    public function assetTypeId(string $code): ?int
    {
        $id = $this->db->fetchOne('SELECT id FROM asset_type WHERE code = ?', [$code]);

        return false === $id ? null : (int) $id;
    }

    /** @return array{id: int, uuid: string, dynamic: bool, asset_type_id: int}|null */
    public function asset(string $uuid): ?array
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT id, uuid, dynamic, asset_type_id FROM asset WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);

        return false === $row ? null : ['id' => (int) $row['id'], 'uuid' => (string) $row['uuid'], 'dynamic' => (bool) $row['dynamic'], 'asset_type_id' => (int) $row['asset_type_id']];
    }

    public function manufacturerId(?string $name): ?int
    {
        if (null === $name) {
            return null;
        }
        $norm = Manufacturer::normalize($name);
        $id = $this->db->fetchOne('SELECT id FROM manufacturer WHERE normalized_name = ?', [$norm]);

        return false !== $id ? (int) $id : (int) $this->db->fetchOne(
            'INSERT INTO manufacturer (name, normalized_name) VALUES (?, ?) ON CONFLICT (normalized_name) DO UPDATE SET name = manufacturer.name RETURNING id',
            [trim($name), $norm],
        );
    }

    public function modelId(int $assetTypeId, ?int $manufacturerId, ?string $name): ?int
    {
        if (null === $name) {
            return null;
        }
        $id = $this->db->fetchOne(
            'SELECT id FROM asset_model WHERE asset_type_id = ? AND manufacturer_id IS NOT DISTINCT FROM ? AND name = ?',
            [$assetTypeId, $manufacturerId, $name],
        );

        return false !== $id ? (int) $id : (int) $this->db->fetchOne(
            'INSERT INTO asset_model (asset_type_id, manufacturer_id, name) VALUES (?, ?, ?) RETURNING id',
            [$assetTypeId, $manufacturerId, $name],
        );
    }

    /** Localização de 1º nível na organização raiz (fase 2: árvore completa na UI). */
    public function locationId(?string $name): ?int
    {
        if (null === $name) {
            return null;
        }
        $org = $this->rootOrganizationId();
        $id = $this->db->fetchOne('SELECT id FROM location WHERE organization_id = ? AND lower(name) = lower(?) ORDER BY parent_id NULLS FIRST LIMIT 1', [$org, $name]);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');

        return false !== $id ? (int) $id : (int) $this->db->fetchOne(
            'INSERT INTO location (organization_id, name, created_at, updated_at) VALUES (?, ?, ?, ?) RETURNING id',
            [$org, $name, $now, $now],
        );
    }

    /** Usuário por login ou e-mail (minúsculo). Cria se não existir. */
    public function userId(?string $login): ?int
    {
        if (null === $login) {
            return null;
        }
        $login = mb_strtolower(trim($login));
        $id = $this->db->fetchOne('SELECT id FROM app_user WHERE username = ? OR lower(email) = ? ORDER BY id LIMIT 1', [$login, $login]);
        if (false !== $id) {
            return (int) $id;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');

        return (int) $this->db->fetchOne(
            'INSERT INTO app_user (username, email, display_name, organization_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [$login, str_contains($login, '@') ? $login : null, null, $this->rootOrganizationId(), $now, $now],
        );
    }

    public function supplierExists(?int $id): bool
    {
        return null === $id || false !== $this->db->fetchOne('SELECT 1 FROM supplier WHERE id = ?', [$id]);
    }

    public function exists(string $table, ?int $id): bool
    {
        if (null === $id) {
            return true;
        }
        if (!\in_array($table, ['supplier', 'contract', 'contract_type', 'license_type', 'license_category', 'license', 'branch', 'invoice',
            'location', 'dcim_rack', 'dcim_device', 'dcim_device_type', 'circuit', 'dcim_cable', 'virt_cluster', 'virt_machine'], true)) {
            throw new \InvalidArgumentException($table);
        }

        return false !== $this->db->fetchOne("SELECT 1 FROM $table WHERE id = ?", [$id]);
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:sP');
    }
}
