<?php

declare(strict_types=1);

namespace App\Ui\Write;

use App\Attachment\Service\AttachmentStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Fornecedores e seus contatos. */
final readonly class SupplierWriter
{
    public function __construct(
        private Connection $db,
        private AttachmentStorage $attachments,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $row = [
            'name' => $p->string('name', 255, required: true),
            'trade_name' => $p->string('trade_name', 255),
            'tax_id' => self::digits($p->string('tax_id', 32)),
            'email' => $p->email('email'),
            'phone' => $p->string('phone', 64),
            'website' => $p->url('website'),
            'address' => $p->string('address', 2000),
            'notes' => $p->string('notes', 5000),
            'active' => $p->bool('active', true),
        ];
        if (null !== $row['tax_id'] && !\in_array(\strlen($row['tax_id']), [11, 14], true)) {
            $p->error('tax_id', 'CNPJ (14 dígitos) ou CPF (11 dígitos).');
        }
        $p->check();

        try {
            // savepoint: a violação de unique não pode abortar a transação externa (25P02)
            return $this->db->transactional(function () use ($id, $row): int {
                if (null === $id) {
                    return (int) $this->db->fetchOne(
                        'INSERT INTO supplier (name, trade_name, tax_id, email, phone, website, address, notes, active, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                        [...array_values($row), Refs::now(), Refs::now()],
                        [8 => \Doctrine\DBAL\ParameterType::BOOLEAN],
                    );
                }
                $this->requireSupplier($id);
                $this->db->update('supplier', $row + ['updated_at' => Refs::now()], ['id' => $id], ['active' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ValidationException(['tax_id' => 'Já existe fornecedor com este CNPJ/CPF.']);
        }
    }

    public function delete(int $id): void
    {
        $this->requireSupplier($id);
        $this->db->transactional(function () use ($id): void {
            $this->attachments->deleteAllOf('supplier', $id);
            // contratos/licenças mantêm o registro, só perdem o vínculo (FK SET NULL)
            $this->db->executeStatement('DELETE FROM supplier WHERE id = ?', [$id]);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveContact(?int $contactId, ?int $supplierId, array $data): int
    {
        $p = new Payload($data);
        $row = [
            'name' => $p->string('name', 255, required: true),
            'role' => $p->string('role', 255),
            'email' => $p->email('email'),
            'phone' => $p->string('phone', 64),
            'mobile' => $p->string('mobile', 64),
            'notes' => $p->string('notes', 2000),
        ];
        $p->check();

        if (null === $contactId) {
            $this->requireSupplier((int) $supplierId);

            return (int) $this->db->fetchOne(
                'INSERT INTO contact (supplier_id, name, role, email, phone, mobile, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$supplierId, ...array_values($row), Refs::now(), Refs::now()],
            );
        }
        if (0 === $this->db->update('contact', $row + ['updated_at' => Refs::now()], ['id' => $contactId])) {
            throw new NotFoundException('Contato não encontrado.');
        }

        return $contactId;
    }

    public function deleteContact(int $contactId): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM contact WHERE id = ?', [$contactId])) {
            throw new NotFoundException('Contato não encontrado.');
        }
    }

    private function requireSupplier(int $id): void
    {
        if (false === $this->db->fetchOne('SELECT 1 FROM supplier WHERE id = ?', [$id])) {
            throw new NotFoundException('Fornecedor não encontrado.');
        }
    }

    private static function digits(?string $v): ?string
    {
        if (null === $v) {
            return null;
        }
        $d = (string) preg_replace('/\D+/', '', $v);

        return '' === $d ? null : $d;
    }
}
