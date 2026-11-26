<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Import;

use App\Inventory\Import\Blacklist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlacklistTest extends TestCase
{
    /** @return iterable<array{string}> */
    public static function invalidSerials(): iterable
    {
        yield ['To Be Filled By O.E.M.'];
        yield ['Default string'];
        yield ['System Serial Number'];
        yield ['0000000000'];
        yield ['1.0.3'];
        yield ['123456789'];
    }

    #[DataProvider('invalidSerials')]
    public function testRejectsPlaceholderSerials(string $serial): void
    {
        self::assertNull(Blacklist::serial($serial));
    }

    public function testKeepsRealSerials(): void
    {
        self::assertSame('BX5Q2J2', Blacklist::serial(' BX5Q2J2 '));
        self::assertSame('PF4XK2LM', Blacklist::serial('PF4XK2LM'));
    }

    public function testUuid(): void
    {
        self::assertNull(Blacklist::uuid('03000200-0400-0500-0006-000700080009'));
        self::assertNull(Blacklist::uuid('FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF'));
        self::assertSame('4c4c4544-0042-3510-8051-b4c04f4a3132', Blacklist::uuid('4C4C4544-0042-3510-8051-B4C04F4A3132'));
    }

    public function testModelAndManufacturer(): void
    {
        self::assertNull(Blacklist::model('System Product Name'));
        self::assertNull(Blacklist::manufacturer('System manufacturer'));
        self::assertSame('OptiPlex 7010', Blacklist::model('OptiPlex 7010'));
    }
}
