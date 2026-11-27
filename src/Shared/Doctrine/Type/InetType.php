<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Tipo nativo `INET` do PostgreSQL: endereço com máscara opcional (ex.: 10.0.0.5/24).
 *
 * Usado no IPAM (ADR-0016) para os operadores de contenção (<<, >>=) com índice GiST `inet_ops`.
 * A validação do texto fica nos serviços (App\Ipam\Service\IpNet); aqui só trafega string.
 */
final class InetType extends Type
{
    public const string NAME = 'inet';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'INET';
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
