<?php

declare(strict_types=1);

namespace App\Ui\Write;

use App\Attachment\Service\AttachmentStorage;
use App\Organization\Entity\Branch;
use App\Organization\Service\BranchResolver;
use App\Organization\Service\Cidr;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/**
 * Matriz e filiais (ADR-0010). Regras:
 * - no máximo uma matriz (verificado sob lock da tabela, sem índice parcial);
 * - coordenadas em par (lat+lng) e dentro da faixa WGS84;
 * - redes normalizadas (Cidr) — após salvar, ativos sem filial cujo IP cai
 *   numa dessas redes recebem a filial.
 */
final readonly class BranchWriter
{
    public function __construct(
        private Connection $db,
        private AttachmentStorage $attachments,
        private BranchResolver $resolver,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{id: int, assigned: int}
     */
    public function save(?int $id, array $data): array
    {
        $p = new Payload($data);
        $row = [
            'name' => $p->string('name', 255, required: true),
            'code' => $p->string('code', 32),
            'kind' => $p->enum('kind', Branch::KINDS, Branch::KIND_BRANCH),
            'tax_id' => self::digits($p->string('tax_id', 32)),
            'phone' => $p->string('phone', 64),
            'email' => $p->email('email'),
            'zip_code' => self::digits($p->string('zip_code', 16)),
            'street' => $p->string('street', 255),
            'number' => $p->string('number', 32),
            'complement' => $p->string('complement', 255),
            'district' => $p->string('district', 128),
            'city' => $p->string('city', 128),
            'state' => null === ($uf = $p->string('state', 2)) ? null : mb_strtoupper($uf),
            'latitude' => self::coordinate($p, 'latitude', 90),
            'longitude' => self::coordinate($p, 'longitude', 180),
            'notes' => $p->string('notes', 5000),
            'active' => $p->bool('active', true),
        ];
        if (null !== $row['tax_id'] && 14 !== \strlen($row['tax_id'])) {
            $p->error('tax_id', 'CNPJ deve ter 14 dígitos.');
        }
        if (null !== $row['zip_code'] && 8 !== \strlen($row['zip_code'])) {
            $p->error('zip_code', 'CEP deve ter 8 dígitos.');
        }
        if (null !== $row['state'] && 1 !== preg_match('/^[A-Z]{2}$/', $row['state'])) {
            $p->error('state', 'UF com 2 letras (ex.: SP).');
        }
        if ((null === $row['latitude']) !== (null === $row['longitude'])) {
            $p->error(null === $row['latitude'] ? 'latitude' : 'longitude', 'Informe latitude e longitude juntas.');
        }
        $row['networks'] = json_encode($this->networks($p), \JSON_UNESCAPED_SLASHES);
        $p->check();

        try {
            $id = $this->db->transactional(function () use ($id, $row): int {
                if (Branch::KIND_HEADQUARTERS === $row['kind']) {
                    // serializa concorrentes; SHARE ROW EXCLUSIVE conflita consigo mesmo
                    $this->db->executeStatement('LOCK TABLE branch IN SHARE ROW EXCLUSIVE MODE');
                    $other = $this->db->fetchOne('SELECT name FROM branch WHERE kind = ? AND id IS DISTINCT FROM ?', [Branch::KIND_HEADQUARTERS, $id]);
                    if (false !== $other) {
                        throw new ValidationException(['kind' => \sprintf('"%s" já está cadastrada como matriz.', $other)]);
                    }
                }
                $types = ['active' => ParameterType::BOOLEAN];
                if (null === $id) {
                    $now = Refs::now();
                    $this->db->insert('branch', $row + ['created_at' => $now, 'updated_at' => $now], $types);

                    return (int) $this->db->lastInsertId();
                }
                if (0 === $this->db->update('branch', $row + ['updated_at' => Refs::now()], ['id' => $id], $types)) {
                    throw new NotFoundException('Filial não encontrada.');
                }

                return $id;
            });
        } catch (UniqueConstraintViolationException $e) {
            $field = str_contains($e->getMessage(), 'uniq_branch_code') ? 'code' : 'name';
            throw new ValidationException([$field => 'code' === $field ? 'Já existe filial com esta sigla.' : 'Já existe filial com este nome.']);
        }

        return ['id' => $id, 'assigned' => \count($this->resolver->assignMissing())];
    }

    /** Ativos da filial ficam sem filial (FK SET NULL); anexos da filial são apagados. */
    public function delete(int $id): void
    {
        if (!$this->exists($id)) {
            throw new NotFoundException('Filial não encontrada.');
        }
        $this->db->transactional(function () use ($id): void {
            $this->attachments->deleteAllOf('branch', $id);
            $this->db->executeStatement('DELETE FROM branch WHERE id = ?', [$id]);
        });
    }

    /**
     * Move vários ativos para a filial (ou remove o vínculo com $id = null).
     *
     * @param list<mixed> $uuids
     */
    public function assignAssets(?int $id, array $uuids, string $actor): int
    {
        if (null !== $id && !$this->exists($id)) {
            throw new NotFoundException('Filial não encontrada.');
        }
        $uuids = array_values(array_filter($uuids, static fn (mixed $u): bool => \is_string($u) && 1 === preg_match('/^[0-9a-f-]{36}$/i', $u)));
        if ([] === $uuids) {
            return 0;
        }

        return $this->db->transactional(function () use ($id, $uuids, $actor): int {
            $rows = $this->db->fetchAllAssociative(<<<'SQL'
                WITH target AS (
                    SELECT a.id, b.name AS old_name FROM asset a LEFT JOIN branch b ON b.id = a.branch_id
                    WHERE a.uuid IN (?) AND a.deleted_at IS NULL AND a.branch_id IS DISTINCT FROM ?
                )
                UPDATE asset a SET branch_id = ?, updated_at = now() FROM target t WHERE a.id = t.id
                RETURNING a.id, t.old_name
                SQL, [$uuids, $id, $id], [\Doctrine\DBAL\ArrayParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER]);
            $newName = null === $id ? null : $this->db->fetchOne('SELECT name FROM branch WHERE id = ?', [$id]);
            foreach ($rows as $r) {
                $this->db->executeStatement(
                    "INSERT INTO asset_history (asset_id, field, old_value, new_value, source, actor, created_at) VALUES (?, 'branch', ?, ?, 'manual', ?, ?)",
                    [$r['id'], $r['old_name'], false === $newName ? null : $newName, $actor, Refs::now()],
                );
            }

            return \count($rows);
        });
    }

    private function exists(int $id): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ?', [$id]);
    }

    /** @return list<string> */
    private function networks(Payload $p): array
    {
        $raw = $p->raw('networks');
        if (null === $raw || '' === $raw) {
            return [];
        }
        // aceita lista JSON ou texto (uma rede por linha / vírgula / espaço)
        $items = \is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) (\is_scalar($raw) ? $raw : ''), -1, \PREG_SPLIT_NO_EMPTY);
        $out = [];
        $bad = [];
        foreach ($items ?: [] as $item) {
            if (!\is_scalar($item) || '' === trim((string) $item)) {
                continue;
            }
            $cidr = Cidr::normalize((string) $item);
            null === $cidr ? $bad[] = (string) $item : $out[$cidr] = true;
        }
        if ([] !== $bad) {
            $p->error('networks', 'Rede inválida: '.implode(', ', \array_slice($bad, 0, 3)).' (use 192.168.10.0/24).');
        }
        if (\count($out) > 64) {
            $p->error('networks', 'Máximo de 64 redes por filial.');
        }

        return array_keys($out);
    }

    private static function coordinate(Payload $p, string $key, int $limit): ?string
    {
        $raw = $p->raw($key);
        if (null === $raw || '' === $raw) {
            return null;
        }
        $v = str_replace(',', '.', trim((string) (\is_scalar($raw) ? $raw : '')));
        if (!is_numeric($v) || abs((float) $v) > $limit) {
            $p->error($key, \sprintf('Valor entre -%d e %d.', $limit, $limit));

            return null;
        }

        return number_format((float) $v, 6, '.', '');
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
