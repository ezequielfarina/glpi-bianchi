<?php

declare(strict_types=1);

namespace App\Ui\Write;

use App\Asset\Enum\AssetStatus;
use App\Asset\Service\InventoryNumberGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Cadastro e edição manual de ativos pela interface (impressoras, monitores,
 * coletores, tablets, telefones... e edição de qualquer ativo inventariado).
 *
 * - Patrimônio: gerado automaticamente quando vazio; único.
 * - Histórico: cada campo alterado vira linha em asset_history (source = manual).
 * - Ativo dinâmico (vem do agente): editar nome/serial/fabricante/modelo TRAVA o
 *   campo (locked_field), senão o próximo inventário desfaria a correção — mesmo
 *   comportamento do GLPI (lockedfields automáticos).
 */
final readonly class AssetWriter
{
    /** Campos que o inventário sobrescreveria → travados ao editar manualmente. */
    private const array LOCK_ON_EDIT = ['name' => 'name', 'serial_number' => 'serialNumber', 'manufacturer_id' => 'manufacturer', 'model_id' => 'model'];

    public function __construct(
        private Connection $db,
        private Refs $refs,
        private InventoryNumberGenerator $numbers,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return string uuid do ativo criado
     */
    public function create(array $data, string $actor): string
    {
        $p = new Payload($data);
        $typeCode = $p->string('type', 64, required: true);
        $name = $p->string('name', 255, required: true);
        $fields = $this->readFields($p);
        $typeId = null === $typeCode ? null : $this->refs->assetTypeId($typeCode);
        if (null !== $typeCode && null === $typeId) {
            $p->error('type', 'Tipo de ativo inválido.');
        }
        $p->check();

        return $this->db->transactional(function () use ($typeId, $name, $fields, $actor): string {
            $inventoryNumber = $fields['inventory_number'] ?? $this->numbers->next();
            $this->assertInventoryNumberFree($inventoryNumber, null);
            $manufacturerId = $this->refs->manufacturerId($fields['manufacturer']);
            $uuid = Uuid::v7()->toRfc4122();
            $now = Refs::now();

            $id = (int) $this->db->fetchOne(<<<'SQL'
                INSERT INTO asset (uuid, organization_id, asset_type_id, name, status, manufacturer_id, model_id, location_id, user_id,
                                   serial_number, inventory_number, attributes, comment, dynamic, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, false, ?, ?) RETURNING id
                SQL, [
                $uuid, $this->refs->rootOrganizationId(), $typeId, $name, $fields['status'] ?? AssetStatus::InUse->value,
                $manufacturerId, $this->refs->modelId((int) $typeId, $manufacturerId, $fields['model']),
                $this->refs->locationId($fields['location']), $this->refs->userId($fields['user']),
                $fields['serial_number'], $inventoryNumber, json_encode((object) ($fields['attributes'] ?? []), \JSON_UNESCAPED_UNICODE),
                $fields['comment'], $now, $now,
            ]);
            $this->history($id, 'asset.created', null, $name, $actor);

            return $uuid;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(string $uuid, array $data, string $actor): void
    {
        $asset = $this->refs->asset($uuid) ?? throw new NotFoundException('Ativo não encontrado.');
        $p = new Payload($data);
        $name = $p->has('name') ? $p->string('name', 255, required: true) : null;
        $fields = $this->readFields($p);
        $p->check();

        $this->db->transactional(function () use ($asset, $p, $name, $fields, $actor): void {
            $old = $this->db->fetchAssociative(<<<'SQL'
                SELECT a.name, a.status, a.manufacturer_id, m.name AS manufacturer, a.model_id, mo.name AS model,
                       a.location_id, l.name AS location, a.user_id, u.username AS "user",
                       a.serial_number, a.inventory_number, a.comment, a.attributes
                FROM asset a
                LEFT JOIN manufacturer m ON m.id = a.manufacturer_id LEFT JOIN asset_model mo ON mo.id = a.model_id
                LEFT JOIN location l ON l.id = a.location_id LEFT JOIN app_user u ON u.id = a.user_id
                WHERE a.id = ?
                SQL, [$asset['id']]) ?: [];

            $set = [];
            $labels = [];
            if (null !== $name) {
                $set['name'] = $name;
            }
            foreach (['status', 'serial_number', 'inventory_number', 'comment'] as $col) {
                if ($p->has($col)) {
                    $set[$col] = $fields[$col];
                }
            }
            if ($p->has('inventory_number')) {
                $set['inventory_number'] ??= $this->numbers->next();
                $this->assertInventoryNumberFree((string) $set['inventory_number'], $asset['id']);
            }
            if ($p->has('manufacturer')) {
                $set['manufacturer_id'] = $this->refs->manufacturerId($fields['manufacturer']);
                $labels['manufacturer_id'] = [$old['manufacturer'] ?? null, $fields['manufacturer']];
            }
            if ($p->has('model')) {
                $manufacturerId = \array_key_exists('manufacturer_id', $set) ? $set['manufacturer_id'] : ($old['manufacturer_id'] ?? null);
                $set['model_id'] = $this->refs->modelId($asset['asset_type_id'], null === $manufacturerId ? null : (int) $manufacturerId, $fields['model']);
                $labels['model_id'] = [$old['model'] ?? null, $fields['model']];
            }
            if ($p->has('location')) {
                $set['location_id'] = $this->refs->locationId($fields['location']);
                $labels['location_id'] = [$old['location'] ?? null, $fields['location']];
            }
            if ($p->has('user')) {
                $set['user_id'] = $this->refs->userId($fields['user']);
                $labels['user_id'] = [$old['user'] ?? null, null === $fields['user'] ? null : mb_strtolower($fields['user'])];
            }
            $attributesChanged = false;
            if ($p->has('attributes')) {
                /** @var array<string, mixed> $current */
                $current = json_decode((string) ($old['attributes'] ?? '{}'), true) ?: [];
                $merged = array_merge($current, $fields['attributes'] ?? []);
                // chave enviada com valor vazio remove o atributo
                $raw = $p->raw('attributes');
                foreach (\is_array($raw) ? $raw : [] as $k => $v) {
                    if (null === $v || '' === trim((string) (\is_scalar($v) ? $v : ''))) {
                        unset($merged[Payload::attributeKey((string) $k)]);
                    }
                }
                ksort($merged);
                ksort($current);
                if ($merged !== $current) {
                    $attributesChanged = true;
                    $this->history($asset['id'], 'attributes', json_encode($current, \JSON_UNESCAPED_UNICODE) ?: null, json_encode($merged, \JSON_UNESCAPED_UNICODE) ?: null, $actor);
                }
                $merged = json_encode((object) $merged, \JSON_UNESCAPED_UNICODE);
            }

            $changed = [];
            foreach ($set as $col => $value) {
                $before = $old[$col] ?? null;
                if ((string) $before === (string) $value) {
                    continue;
                }
                $changed[$col] = $value;
                [$oldLabel, $newLabel] = $labels[$col] ?? [$before, $value];
                $this->history($asset['id'], self::historyField($col), null === $oldLabel ? null : (string) $oldLabel, null === $newLabel ? null : (string) $newLabel, $actor);
                if ($asset['dynamic'] && isset(self::LOCK_ON_EDIT[$col])) {
                    $this->db->executeStatement(
                        'INSERT INTO locked_field (asset_id, field, created_at) VALUES (?, ?, ?) ON CONFLICT (asset_id, field) DO NOTHING',
                        [$asset['id'], self::LOCK_ON_EDIT[$col], Refs::now()],
                    );
                }
            }
            if ($attributesChanged) {
                $changed['attributes'] = $merged;
            }
            if ([] === $changed) {
                return;
            }
            $changed['updated_at'] = Refs::now();
            $this->db->update('asset', $changed, ['id' => $asset['id']]);
        });
    }

    /** Envia para a lixeira (soft delete); histórico e anexos são preservados. */
    public function delete(string $uuid, string $actor): void
    {
        $asset = $this->refs->asset($uuid) ?? throw new NotFoundException('Ativo não encontrado.');
        $this->db->executeStatement('UPDATE asset SET deleted_at = ?, updated_at = ? WHERE id = ?', [Refs::now(), Refs::now(), $asset['id']]);
        $this->history($asset['id'], 'asset.deleted', null, null, $actor);
    }

    /** @return array<string, mixed> */
    private function readFields(Payload $p): array
    {
        return [
            'status' => $p->enum('status', array_map(static fn (AssetStatus $s): string => $s->value, AssetStatus::cases())),
            'manufacturer' => $p->string('manufacturer', 255),
            'model' => $p->string('model', 255),
            'serial_number' => $p->string('serial_number', 255),
            'inventory_number' => $p->string('inventory_number', 255),
            'location' => $p->string('location', 255),
            'user' => $p->string('user', 255),
            'comment' => $p->string('comment', 5000),
            'attributes' => $p->attributes('attributes'),
        ];
    }


    private function assertInventoryNumberFree(string $number, ?int $exceptId): void
    {
        $other = $this->db->fetchOne('SELECT name FROM asset WHERE inventory_number = ? AND id IS DISTINCT FROM ?', [$number, $exceptId]);
        if (false !== $other) {
            throw new ValidationException(['inventory_number' => \sprintf('Patrimônio %s já usado por "%s".', $number, $other)]);
        }
    }

    private function history(int $assetId, string $field, ?string $old, ?string $new, string $actor): void
    {
        $this->db->executeStatement(
            "INSERT INTO asset_history (asset_id, field, old_value, new_value, source, actor, created_at) VALUES (?, ?, ?, ?, 'manual', ?, ?)",
            [$assetId, $field, $old, $new, $actor, Refs::now()],
        );
    }

    private static function historyField(string $col): string
    {
        return match ($col) {
            'serial_number' => 'serialNumber',
            'inventory_number' => 'inventoryNumber',
            'manufacturer_id' => 'manufacturer',
            'model_id' => 'model',
            'location_id' => 'location',
            'user_id' => 'user',
            default => $col,
        };
    }
}
