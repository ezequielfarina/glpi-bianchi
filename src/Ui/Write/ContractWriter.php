<?php

declare(strict_types=1);

namespace App\Ui\Write;

use App\Attachment\Service\AttachmentStorage;
use App\Contract\Entity\Contract;
use Doctrine\DBAL\Connection;

/** Contratos (vigência, renovação, valor) e vínculo com ativos. */
final readonly class ContractWriter
{
    public function __construct(
        private Connection $db,
        private Refs $refs,
        private AttachmentStorage $attachments,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $row = [
            'title' => $p->string('title', 255, required: true),
            'number' => $p->string('number', 64),
            'supplier_id' => $p->int('supplier_id', 1),
            'contract_type_id' => $p->int('contract_type_id', 1),
            'description' => $p->string('description', 5000),
            'start_date' => $p->date('start_date'),
            'end_date' => $p->date('end_date'),
            'renewal' => $p->enum('renewal', Contract::RENEWALS, 'none'),
            'notice_days' => $p->int('notice_days', 0, 3650),
            'billing_period' => $p->enum('billing_period', Contract::BILLING_PERIODS, 'monthly'),
            'value' => $p->money('value'),
        ];
        if (!$this->refs->exists('supplier', $row['supplier_id'])) {
            $p->error('supplier_id', 'Fornecedor inexistente.');
        }
        if (!$this->refs->exists('contract_type', $row['contract_type_id'])) {
            $p->error('contract_type_id', 'Tipo de contrato inexistente.');
        }
        if (null !== $row['start_date'] && null !== $row['end_date'] && $row['end_date'] < $row['start_date']) {
            $p->error('end_date', 'Término antes do início.');
        }
        $p->check();

        if (null === $id) {
            return (int) $this->db->fetchOne(
                'INSERT INTO contract (title, number, supplier_id, contract_type_id, description, start_date, end_date, renewal, notice_days, billing_period, value, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [...array_values($row), Refs::now(), Refs::now()],
            );
        }
        if (0 === $this->db->update('contract', $row + ['updated_at' => Refs::now()], ['id' => $id])) {
            throw new NotFoundException('Contrato não encontrado.');
        }

        return $id;
    }

    public function delete(int $id): void
    {
        $this->db->transactional(function () use ($id): void {
            $this->attachments->deleteAllOf('contract', $id);
            if (0 === $this->db->executeStatement('DELETE FROM contract WHERE id = ?', [$id])) {
                throw new NotFoundException('Contrato não encontrado.');
            }
        });
    }

    public function linkAsset(int $contractId, string $assetUuid): void
    {
        $asset = $this->refs->asset($assetUuid) ?? throw new NotFoundException('Ativo não encontrado.');
        if (!$this->refs->exists('contract', $contractId)) {
            throw new NotFoundException('Contrato não encontrado.');
        }
        $this->db->executeStatement('INSERT INTO contract_asset (contract_id, asset_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$contractId, $asset['id']]);
    }

    public function unlinkAsset(int $contractId, string $assetUuid): void
    {
        $asset = $this->refs->asset($assetUuid) ?? throw new NotFoundException('Ativo não encontrado.');
        $this->db->executeStatement('DELETE FROM contract_asset WHERE contract_id = ? AND asset_id = ?', [$contractId, $asset['id']]);
    }
}
