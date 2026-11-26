<?php

declare(strict_types=1);

namespace App\Ui\Write;

use App\Attachment\Service\AttachmentStorage;
use App\License\Entity\License;
use App\Shared\Security\SecretBox;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Licenças e atribuições (assentos).
 *
 * Atribuição respeita o modelo: device/volume → ativo; user → usuário (login/e-mail).
 * Sem assentos livres → 422, a menos que `force: true` (registra excedente conscientemente).
 */
final readonly class LicenseWriter
{
    public function __construct(
        private Connection $db,
        private Refs $refs,
        private SecretBox $secretBox,
        private AttachmentStorage $attachments,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $typeId = $p->int('license_type_id', 1, required: true);
        $row = [
            'name' => $p->string('name', 255, required: true),
            'license_type_id' => $typeId,
            'license_category_id' => $p->int('license_category_id', 1),
            'supplier_id' => $p->int('supplier_id', 1),
            'contract_id' => $p->int('contract_id', 1),
            'assignment' => $p->enum('assignment', License::ASSIGNMENTS),
            'seats' => $p->int('seats', 0, 1_000_000) ?? 1,
            'unit_price' => $p->money('unit_price'),
            'purchase_date' => $p->date('purchase_date'),
            'expires_at' => $p->date('expires_at'),
            'software_match' => $p->string('software_match', 255),
            'notes' => $p->string('notes', 5000),
        ];
        foreach (['license_type' => 'license_type_id', 'license_category' => 'license_category_id', 'supplier' => 'supplier_id', 'contract' => 'contract_id'] as $table => $col) {
            if (!$this->refs->exists($table, $row[$col])) {
                $p->error($col, 'Registro inexistente.');
            }
        }
        // modelo de atribuição padrão = o do tipo de licença
        if (null === $row['assignment'] && null !== $typeId) {
            $row['assignment'] = (string) ($this->db->fetchOne('SELECT assignment FROM license_type WHERE id = ?', [$typeId]) ?: License::ASSIGN_DEVICE);
        }

        $key = $p->has('license_key') ? $p->string('license_key', 2000) : false;
        if (\is_string($key) && !$this->secretBox->isConfigured()) {
            $p->error('license_key', 'Criptografia não configurada no servidor (DATA_ENCRYPTION_KEY). Rode o install.sh.');
        }
        $p->check();

        if (false !== $key) {
            $row['license_key'] = null === $key ? null : $this->secretBox->encrypt($key);
        }

        if (null === $id) {
            $cols = array_keys($row);
            $sql = \sprintf('INSERT INTO license (%s, created_at, updated_at) VALUES (%s, ?, ?) RETURNING id', implode(', ', $cols), implode(', ', array_fill(0, \count($cols), '?')));

            return (int) $this->db->fetchOne($sql, [...array_values($row), Refs::now(), Refs::now()]);
        }
        if (0 === $this->db->update('license', $row + ['updated_at' => Refs::now()], ['id' => $id])) {
            throw new NotFoundException('Licença não encontrada.');
        }

        return $id;
    }

    public function delete(int $id): void
    {
        $this->db->transactional(function () use ($id): void {
            $this->attachments->deleteAllOf('license', $id);
            if (0 === $this->db->executeStatement('DELETE FROM license WHERE id = ?', [$id])) {
                throw new NotFoundException('Licença não encontrada.');
            }
        });
    }

    /** @param array<string, mixed> $data {asset_uuid}|{user}, notes?, force? */
    public function assign(int $licenseId, array $data): int
    {
        $license = $this->db->fetchAssociative('SELECT assignment, seats, (SELECT count(*) FROM license_assignment WHERE license_id = license.id) AS used FROM license WHERE id = ?', [$licenseId])
            ?: throw new NotFoundException('Licença não encontrada.');
        $p = new Payload($data);
        $assetUuid = $p->string('asset_uuid', 36);
        $user = $p->string('user', 255);
        $notes = $p->string('notes', 1000);
        $force = $p->bool('force');

        $isUser = License::ASSIGN_USER === $license['assignment'];
        if ($isUser && null === $user) {
            $p->error('user', 'Licença por usuário: informe o login ou e-mail.');
        }
        if (!$isUser && null === $assetUuid) {
            $p->error('asset_uuid', 'Licença por máquina/volume: selecione o ativo.');
        }
        if (!$force && (int) $license['used'] >= (int) $license['seats']) {
            $p->error('seats', \sprintf('Sem assentos livres (%d de %d em uso). Aumente a quantidade ou confirme o excedente.', $license['used'], $license['seats']));
        }
        $assetId = null;
        if (!$isUser && null !== $assetUuid) {
            $assetId = $this->refs->asset($assetUuid)['id'] ?? null;
            if (null === $assetId) {
                $p->error('asset_uuid', 'Ativo não encontrado.');
            }
        }
        $p->check();

        try {
            return (int) $this->db->fetchOne(
                'INSERT INTO license_assignment (license_id, asset_id, user_id, notes, assigned_at) VALUES (?, ?, ?, ?, ?) RETURNING id',
                [$licenseId, $assetId, $isUser ? $this->refs->userId($user) : null, $notes, Refs::now()],
            );
        } catch (UniqueConstraintViolationException) {
            throw new ValidationException([$isUser ? 'user' : 'asset_uuid' => 'Já atribuída a este '.($isUser ? 'usuário' : 'ativo').'.']);
        }
    }

    public function unassign(int $assignmentId): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM license_assignment WHERE id = ?', [$assignmentId])) {
            throw new NotFoundException('Atribuição não encontrada.');
        }
    }

    public function revealKey(int $licenseId): ?string
    {
        $enc = $this->db->fetchOne('SELECT license_key FROM license WHERE id = ?', [$licenseId]);
        if (false === $enc) {
            throw new NotFoundException('Licença não encontrada.');
        }

        return null === $enc ? null : $this->secretBox->decrypt((string) $enc);
    }
}
