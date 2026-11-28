<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Type;

use App\Shared\Security\SecretBox;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * JSON cifrado em repouso (ADR-0019): array → JSON → zlib → SecretBox (XChaCha20-Poly1305, contexto
 * "sealed:<tabela.coluna>"). Usado no payload bruto do inventário, que traz o retrato completo da
 * máquina (usuários, softwares, seriais, rede). Coluna TEXT; o banco nunca vê o conteúdo em claro.
 *
 * Tipos do DBAL não são serviços: o SecretBox é injetado no boot do Kernel (App\Kernel::boot).
 */
final class SealedJsonType extends Type
{
    public const string NAME = 'sealed_json';
    public const string CONTEXT = 'sealed:raw_inventory.payload';
    private static ?SecretBox $box = null;

    public static function useBox(SecretBox $box): void
    {
        self::$box = $box;
    }

    public static function seal(mixed $value): string
    {
        $json = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);

        return self::box()->encrypt((string) gzcompress($json, 6), self::CONTEXT);
    }

    /** @return array<mixed> */
    public static function open(string $sealed): array
    {
        $json = gzuncompress(self::box()->decrypt($sealed, self::CONTEXT));
        if (false === $json) {
            throw new \RuntimeException('Payload cifrado corrompido.');
        }
        $v = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($v) ? $v : [];
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : self::seal($value);
    }

    /** @return array<mixed>|null */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        return null === $value || '' === $value ? null : self::open((string) $value);
    }

    private static function box(): SecretBox
    {
        return self::$box ?? throw new \LogicException('SealedJsonType sem SecretBox: o Kernel não foi inicializado.');
    }
}
