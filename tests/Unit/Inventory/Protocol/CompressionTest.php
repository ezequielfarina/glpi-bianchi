<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Protocol;

use App\Inventory\Protocol\Compression;
use App\Inventory\Protocol\PayloadFormat;
use App\Inventory\Protocol\ProtocolException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompressionTest extends TestCase
{
    /** @return iterable<string, array{string, Compression}> */
    public static function contentTypes(): iterable
    {
        yield 'zlib (padrão do GLPI Agent)' => ['application/x-compress-zlib', Compression::Zlib];
        yield 'zlib curto' => ['application/x-zlib', Compression::Zlib];
        yield 'gzip' => ['application/x-compress-gzip', Compression::Gzip];
        yield 'deflate' => ['application/x-deflate', Compression::Deflate];
        yield 'json puro' => ['application/json', Compression::None];
        yield 'xml puro' => ['application/xml; charset=UTF-8', Compression::None];
        yield 'text/plain (agente antigo)' => ['text/plain', Compression::None];
    }

    #[DataProvider('contentTypes')]
    public function testDetectsCompressionFromContentType(string $contentType, Compression $expected): void
    {
        self::assertSame($expected, Compression::fromContentType($contentType));
    }

    public function testRoundTrip(): void
    {
        $json = '{"action":"contact","deviceid":"x"}';
        foreach (Compression::cases() as $c) {
            self::assertSame($json, $c->decompress($c->compress($json), 1024), $c->value);
        }
    }

    public function testRejectsPayloadAboveLimit(): void
    {
        $this->expectException(ProtocolException::class);
        Compression::Zlib->decompress((string) gzcompress(str_repeat('a', 10_000)), 100);
    }

    public function testResponseContentTypeMirrorsRequest(): void
    {
        self::assertSame('application/x-compress-zlib', Compression::Zlib->contentType(PayloadFormat::Json));
        self::assertSame('application/xml', Compression::None->contentType(PayloadFormat::Xml));
    }
}
