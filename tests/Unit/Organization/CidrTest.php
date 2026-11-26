<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization;

use App\Organization\Service\Cidr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CidrTest extends TestCase
{
    /** @return iterable<array{string, string|null}> */
    public static function cases(): iterable
    {
        yield 'rede /24' => ['192.168.10.0/24', '192.168.10.0/24'];
        yield 'host com prefixo zera bits' => ['192.168.10.77/24', '192.168.10.0/24'];
        yield '/22 não alinhado' => ['192.168.13.1/22', '192.168.12.0/22'];
        yield 'IP sozinho vira /32' => ['10.0.0.5', '10.0.0.5/32'];
        yield 'espaços' => [' 172.16.5.9/16 ', '172.16.0.0/16'];
        yield 'IPv6' => ['2001:db8::1/48', '2001:db8::/48'];
        yield 'prefixo acima de 32' => ['10.0.0.0/33', null];
        yield 'prefixo não numérico' => ['10.0.0.0/x', null];
        yield 'lixo' => ['abc', null];
        yield 'vazio' => ['', null];
    }

    #[DataProvider('cases')]
    public function testNormalize(string $input, ?string $expected): void
    {
        self::assertSame($expected, Cidr::normalize($input));
    }
}
