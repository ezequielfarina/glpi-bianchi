<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Import;

use App\Inventory\Import\Value;
use App\Network\Entity\IpAddress;
use App\Network\Entity\NetworkPort;
use PHPUnit\Framework\TestCase;

final class ValueTest extends TestCase
{
    public function testDiscardsVendorPlaceholders(): void
    {
        self::assertNull(Value::str('To be filled by O.E.M.'));
        self::assertNull(Value::str('  Default string '));
        self::assertNull(Value::str(''));
        self::assertSame('BX5Q2J2', Value::str(' BX5Q2J2 '));
    }

    public function testNumbers(): void
    {
        self::assertSame(2933, Value::int('2933'));
        self::assertSame(2200, Value::int(2200.4));
        self::assertNull(Value::int('n/a'));
    }

    public function testSingleObjectBecomesList(): void
    {
        self::assertCount(1, Value::list(['name' => 'cpu0']));
        self::assertCount(2, Value::list([['a' => 1], ['b' => 2]]));
        self::assertSame([], Value::list(null));
    }

    public function testMacNormalization(): void
    {
        self::assertSame('b0:26:28:aa:bb:01', NetworkPort::normalizeMac('B0-26-28-AA-BB-01'));
        self::assertNull(NetworkPort::normalizeMac('00:00:00:00:00:00'));
        self::assertNull(NetworkPort::normalizeMac('garbage'));
    }

    public function testMaskToPrefix(): void
    {
        self::assertSame(24, IpAddress::maskToPrefix('255.255.255.0'));
        self::assertSame(23, IpAddress::maskToPrefix('255.255.254.0'));
        self::assertSame(64, IpAddress::maskToPrefix('64'));
        self::assertNull(IpAddress::maskToPrefix(null));
    }
}
