<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wifi;

use App\Wifi\Unifi\MacAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MacAddressTest extends TestCase
{
    /** @return iterable<array{string, ?string}> */
    public static function inputs(): iterable
    {
        yield ['AA:BB:CC:DD:EE:FF', 'aa:bb:cc:dd:ee:ff'];
        yield ['aa-bb-cc-dd-ee-ff', 'aa:bb:cc:dd:ee:ff'];
        yield ['aabb.ccdd.eeff', 'aa:bb:cc:dd:ee:ff'];
        yield ['AABBCCDDEEFF', 'aa:bb:cc:dd:ee:ff'];
        yield [' aa bb cc dd ee ff ', 'aa:bb:cc:dd:ee:ff'];
        yield ['aa:bb:cc:dd:ee', null];
        yield ['aa:bb:cc:dd:ee:fg', null];
        yield ['aa:bb:cc:dd:ee:ff:00', null];
        yield ['00:00:00:00:00:00', null];
        yield ['ff:ff:ff:ff:ff:ff', null];
        yield ['a:b:c:d:e:f1234567', null];
    }

    #[DataProvider('inputs')]
    public function testNormalize(string $in, ?string $expected): void
    {
        self::assertSame($expected, MacAddress::normalize($in));
    }

    public function testFlags(): void
    {
        self::assertTrue(MacAddress::isRandomized('02:11:22:33:44:55'));
        self::assertTrue(MacAddress::isRandomized('da:a1:19:00:00:01'));
        self::assertFalse(MacAddress::isRandomized('00:1b:63:00:00:01'));
        self::assertTrue(MacAddress::isMulticast('01:00:5e:00:00:01'));
        self::assertFalse(MacAddress::isMulticast('00:1b:63:00:00:01'));
    }
}
