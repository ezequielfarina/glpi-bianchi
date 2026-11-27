<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stock;

use App\Stock\Service\StockLedger;
use App\Ui\Write\ValidationException;
use PHPUnit\Framework\TestCase;

/** Validação de entrada do estoque (ADR-0014): valores, séries, datas e linhas. */
final class StockLedgerInputTest extends TestCase
{
    public function testMoney(): void
    {
        self::assertSame('1234.56', StockLedger::money('1.234,56'));
        self::assertSame('1234.56', StockLedger::money('1234.56'));
        self::assertSame('25.90', StockLedger::money('R$ 25,90'));
        self::assertSame('10.00', StockLedger::money(10));
        self::assertNull(StockLedger::money(''));
        self::assertFalse(StockLedger::money('-1'));
        self::assertFalse(StockLedger::money('abc'));
    }

    public function testSerials(): void
    {
        self::assertSame(['A1', null, 'A3'], StockLedger::serials("A1\n\n A3 \n\n"));
        self::assertSame([], StockLedger::serials(null));
        self::assertSame(['X'], StockLedger::serials(['X', '']));
    }

    public function testPositiveInt(): void
    {
        self::assertSame(5, StockLedger::positiveInt('5'));
        self::assertNull(StockLedger::positiveInt(0));
        self::assertNull(StockLedger::positiveInt('-3'));
        self::assertNull(StockLedger::positiveInt('1.5'));
    }

    public function testOccurredAt(): void
    {
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $yesterday = (new \DateTimeImmutable('yesterday', $tz))->format('Y-m-d');
        self::assertStringStartsWith($yesterday.' 12:00:00', StockLedger::occurredAt($yesterday));
        $this->expectException(ValidationException::class);
        StockLedger::occurredAt((new \DateTimeImmutable('+2 days', $tz))->format('Y-m-d'));
    }

    public function testLinesRejectsEmptyAndNonList(): void
    {
        foreach ([null, [], ['a' => []], [1]] as $bad) {
            try {
                StockLedger::lines($bad, 'items');
                self::fail('esperava erro');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors);
            }
        }
        self::assertCount(1, StockLedger::lines([['stock_item_id' => 1]], 'items'));
    }
}
