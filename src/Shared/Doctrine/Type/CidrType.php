<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Tipo nativo `CIDR` do PostgreSQL: rede (ex.: 10.0.0.0/24) — o PostgreSQL rejeita bits de host ligados.
 *
 * Usado no IPAM (ADR-0016) para os operadores de contenção (<<, >>=) com índice GiST `inet_ops`.
 * A validação do texto fica nos serviços (App\Ipam\Service\IpNet); aqui só trafega string.
 */
final class CidrType extends Type
{
    public const string NAME = 'cidr';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'CIDR';
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
