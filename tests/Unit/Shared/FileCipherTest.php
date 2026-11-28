<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Security\FileCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-0019: anexos cifrados em fluxo (secretstream XChaCha20-Poly1305). */
final class FileCipherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/fc-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    /** @return iterable<string, array{int}> */
    public static function sizes(): iterable
    {
        yield '1 byte' => [1];
        yield 'bloco exato' => [65536];
        yield 'vários blocos' => [200_001];
    }

    #[DataProvider('sizes')]
    public function testRoundTripInPlace(int $size): void
    {
        $path = $this->dir.'/f';
        $plain = random_bytes($size);
        file_put_contents($path, $plain);
        $key = FileCipher::newKey();
        FileCipher::encryptFile($path, $path, $key);
        self::assertTrue(FileCipher::isEncrypted($path));
        if ($size >= 16) { // 1 byte aleatório pode aparecer por acaso no texto cifrado
            self::assertStringNotContainsString(substr($plain, 0, 64), (string) file_get_contents($path));
        }
        self::assertSame($plain, FileCipher::decryptToString($path, $key));
    }

    public function testTamperAndTruncationAreDetected(): void
    {
        $path = $this->dir.'/f';
        file_put_contents($path, str_repeat('nota fiscal ', 20_000));
        $key = FileCipher::newKey();
        FileCipher::encryptFile($path, $path, $key);
        $data = (string) file_get_contents($path);

        file_put_contents($path, substr($data, 0, 100).\chr(\ord($data[100]) ^ 1).substr($data, 101));
        try {
            FileCipher::decryptToString($path, $key);
            self::fail('alteração não detectada');
        } catch (\RuntimeException) {
        }

        file_put_contents($path, substr($data, 0, 6 + 24 + 65536 + 17)); // só o 1º bloco
        $this->expectException(\RuntimeException::class);
        FileCipher::decryptToString($path, $key);
    }
}
