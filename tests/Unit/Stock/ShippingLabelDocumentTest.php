<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stock;

use App\Stock\Service\ShippingLabelDocument;
use PHPUnit\Framework\TestCase;

/** Etiqueta .odt a partir do modelo Entrega_TI (ADR-0014). */
final class ShippingLabelDocumentTest extends TestCase
{
    public function testFillsTemplate(): void
    {
        $doc = (new ShippingLabelDocument(\dirname(__DIR__, 3).'/resources/labels/entrega-ti.odt'))
            ->render(['sender' => 'ti', 'destination' => 'Chapecó - 08', 'department' => 'Compras', 'attention' => 'Clemair & Cia']);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'odt');
        file_put_contents($tmp, $doc);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp));
        self::assertSame('mimetype', $zip->getNameIndex(0));
        $xml = (string) $zip->getFromName('content.xml');
        $zip->close();
        unlink($tmp);

        self::assertStringContainsString('>CHAPECÓ - 08</text:p>', $xml);
        self::assertStringContainsString('COMPRAS A/C CLEMAIR &amp; CIA', $xml);
        self::assertStringContainsString('REMETENTE:</text:span> <text:span text:style-name="T2">TI<', $xml);
        self::assertStringNotContainsString('text:drop-down', $xml);
        self::assertNotFalse(simplexml_load_string($xml));
    }
}
