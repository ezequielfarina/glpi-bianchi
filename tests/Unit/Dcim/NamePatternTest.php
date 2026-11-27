<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dcim;

use App\Dcim\Service\NamePattern;
use App\Ui\Write\ValidationException;
use PHPUnit\Framework\TestCase;

final class NamePatternTest extends TestCase
{
    public function testExpandsRangesListsLettersAndPadding(): void
    {
        self::assertSame(['Gi1/0/1', 'Gi1/0/2', 'Gi1/0/3'], NamePattern::expand('Gi1/0/[1-3]'));
        self::assertSame(['PT-1', 'PT-2', 'PT-7'], NamePattern::expand('PT-[1-2,7]'));
        self::assertSame(['01/a', '01/b', '02/a', '02/b'], NamePattern::expand('[01-02]/[a-b]'));
        self::assertSame(['eth0'], NamePattern::expand('eth0'));
    }

    public function testRejectsHugeOrInvalidPatterns(): void
    {
        $this->expectException(ValidationException::class);
        NamePattern::expand('[1-5000]');
    }
}
