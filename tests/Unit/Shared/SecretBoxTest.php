<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Security\SecretBox;
use PHPUnit\Framework\TestCase;

/** ADR-0019: XChaCha20-Poly1305 com contexto, legado XSalsa20 e chaveiro para rotação. */
final class SecretBoxTest extends TestCase
{
    private string $k1;
    private string $k2;

    protected function setUp(): void
    {
        $this->k1 = base64_encode(str_repeat("\x01", 32));
        $this->k2 = base64_encode(str_repeat("\x02", 32));
    }

    public function testRoundTripWithContext(): void
    {
        $box = new SecretBox($this->k1);
        $enc = $box->encrypt('XXXX-YYYY', 'license.key');
        self::assertStringStartsWith('v2.'.$box->currentKeyId().'.', $enc);
        self::assertStringNotContainsString('XXXX', $enc);
        self::assertSame('XXXX-YYYY', $box->decrypt($enc, 'license.key'));
        self::assertNotSame($enc, $box->encrypt('XXXX-YYYY', 'license.key'), 'nonce aleatório');
    }

    public function testCiphertextIsBoundToContext(): void
    {
        $box = new SecretBox($this->k1);
        $enc = $box->encrypt('segredo', 'account.totp:1');
        $this->expectException(\RuntimeException::class);
        $box->decrypt($enc, 'account.totp:2');
    }

    public function testTamperingIsDetected(): void
    {
        $box = new SecretBox($this->k1);
        $enc = $box->encrypt('segredo');
        $last = $enc[-1];
        $this->expectException(\RuntimeException::class);
        $box->decrypt(substr($enc, 0, -1).('A' === $last ? 'B' : 'A'));
    }

    public function testLegacyFormatStillDecrypts(): void
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $legacy = base64_encode($nonce.sodium_crypto_secretbox('antigo', $nonce, str_repeat("\x01", 32)));
        $box = new SecretBox($this->k1);
        self::assertSame('antigo', $box->decrypt($legacy, 'qualquer'));
        self::assertTrue($box->needsRotation($legacy));
    }

    public function testRotationWithOldKeys(): void
    {
        $old = new SecretBox($this->k1);
        $enc = $old->encrypt('valor', 'channel.target');
        $rotated = new SecretBox($this->k2, $this->k1);
        self::assertTrue($rotated->needsRotation($enc));
        $re = $rotated->reencrypt($enc, 'channel.target');
        self::assertFalse($rotated->needsRotation($re));
        self::assertSame('valor', (new SecretBox($this->k2))->decrypt($re, 'channel.target'));
        $this->expectException(\RuntimeException::class);
        (new SecretBox($this->k2))->decrypt($enc, 'channel.target'); // chave antiga fora do chaveiro
    }

    public function testInvalidCurrentKeyNeverFallsBackToOldKey(): void
    {
        $box = new SecretBox('curta', $this->k1);
        self::assertFalse($box->isConfigured());
        $this->expectException(\RuntimeException::class);
        $box->encrypt('x');
    }
}
