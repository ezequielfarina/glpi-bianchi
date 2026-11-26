<?php

declare(strict_types=1);

namespace App\Ui\Write;

use App\License\Entity\License;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Cadastros auxiliares editáveis: tipos/categorias de licença, tipos de contrato. */
final readonly class LookupWriter
{
    public const array KINDS = [
        'license_types' => 'license_type',
        'license_categories' => 'license_category',
        'contract_types' => 'contract_type',
    ];

    public function __construct(private Connection $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function save(string $kind, ?int $id, array $data): int
    {
        $table = self::KINDS[$kind] ?? throw new NotFoundException('Cadastro desconhecido.');
        $p = new Payload($data);
        $row = ['name' => $p->string('name', 128, required: true)];
        if ('license_type' === $table) {
            $row['assignment'] = $p->enum('assignment', License::ASSIGNMENTS, License::ASSIGN_DEVICE);
        }
        $p->check();

        try {
            if (null === $id) {
                $cols = array_keys($row);

                return (int) $this->db->fetchOne(
                    \sprintf('INSERT INTO %s (%s) VALUES (%s) RETURNING id', $table, implode(', ', $cols), implode(', ', array_fill(0, \count($cols), '?'))),
                    array_values($row),
                );
            }
            if (0 === $this->db->update($table, $row, ['id' => $id])) {
                throw new NotFoundException('Registro não encontrado.');
            }

            return $id;
        } catch (UniqueConstraintViolationException) {
            throw new ValidationException(['name' => 'Já existe um registro com este nome.']);
        }
    }

    public function delete(string $kind, int $id): void
    {
        $table = self::KINDS[$kind] ?? throw new NotFoundException('Cadastro desconhecido.');
        try {
            if (0 === $this->db->executeStatement(\sprintf('DELETE FROM %s WHERE id = ?', $table), [$id])) {
                throw new NotFoundException('Registro não encontrado.');
            }
        } catch (ForeignKeyConstraintViolationException) {
            throw new ValidationException(['name' => 'Em uso por licenças — altere-as antes de excluir.']);
        }
    }
}
