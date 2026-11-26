<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Tipo DBAL para a extensão `ltree` do PostgreSQL.
 *
 * Usado nas hierarquias (Organization, Location). O valor é mantido por trigger
 * no banco a partir de `parent_id` — a aplicação apenas lê. Ver ADR-0004.
 *
 * Exemplo de valor: "1.4.17" (ids dos ancestrais até o próprio nó).
 */
final class LtreeType extends Type
{
    public const string NAME = 'ltree';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'LTREE';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : (string) $value;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
