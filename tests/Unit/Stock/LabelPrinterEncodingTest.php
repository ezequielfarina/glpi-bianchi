<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stock;

use App\Stock\Service\LabelPrinter;
use PHPUnit\Framework\TestCase;

/** Codificação do bitmap da etiqueta em PPLB (GW, bit 0 = preto) e ZPL (^GFA, bit 1 = preto) — ADR-0014. */
final class LabelPrinterEncodingTest extends TestCase
{
    private function image(): \GdImage
    {
        // 10 × 2: primeira linha com os 3 primeiros pontos pretos; segunda toda branca
        $img = imagecreate(10, 2);
        self::assertNotFalse($img);
        $white = (int) imagecolorallocate($img, 255, 255, 255);
        $black = (int) imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, 9, 1, $white);
        imagefilledrectangle($img, 0, 0, 2, 0, $black);

        return $img;
    }

    public function testZpl(): void
    {
        $zpl = LabelPrinter::zpl($this->image());
        self::assertSame("^XA^PW10^LL2^LH0,0^FO0,0^GFA,4,4,2,E0000000^FS^PQ1^XZ\n", $zpl);
    }

    public function testPplaDownloadsBmpAndPrintsImageField(): void
    {
        $job = LabelPrinter::ppla($this->image());
        self::assertStringStartsWith("\x02IABBIAETQ\rBM", $job);
        self::assertStringEndsWith("\x02L\rD11\r1Y1100000000000BIAETQ\rQ0001\rE\r", $job);
        $bmp = LabelPrinter::bmp1($this->image());
        self::assertSame(62 + 2 * 4, \strlen($bmp));                 // 2 linhas × 4 bytes (alinhamento)
        self::assertSame("\xFF\xFF\xFF\xFF", substr($bmp, 62, 4));     // linha de baixo (branca) primeiro
        self::assertSame("\x1F\xFF\xFF\xFF", substr($bmp, 66, 4));     // 3 pontos pretos = bits 0
    }

    public function testPplbInvertsBits(): void
    {
        $job = LabelPrinter::pplb($this->image());
        self::assertStringContainsString("\nq10\nQ2,24\n", $job);
        $data = substr($job, strpos($job, 'GW0,0,2,2,') + \strlen('GW0,0,2,2,'), 4);
        self::assertSame("\x1F\xFF\xFF\xFF", $data);
        self::assertStringEndsWith("\nP1\n", $job);
    }
}
