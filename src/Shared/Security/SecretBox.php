<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Criptografia simétrica autenticada (libsodium secretbox / XSalsa20-Poly1305) para
 * dados sensíveis em repouso — ex.: chaves de licença de software.
 *
 * Chave: DATA_ENCRYPTION_KEY = base64 de 32 bytes (gerada pelo install.sh:
 * `openssl rand -base64 32`). Perder a chave = perder os dados cifrados: ela entra
 * no backup junto com o .env.local. Formato gravado: base64(nonce || ciphertext).
 */
final readonly class SecretBox
{
    public function __construct(
        #[\SensitiveParameter]
        private string $dataEncryptionKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->key();
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $key = $this->key() ?? throw new \RuntimeException('DATA_ENCRYPTION_KEY não configurada: rode o deploy/lxc/install.sh para gerá-la.');
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    public function decrypt(string $encoded): string
    {
        $key = $this->key() ?? throw new \RuntimeException('DATA_ENCRYPTION_KEY não configurada.');
        $raw = base64_decode($encoded, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Valor cifrado inválido.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );
        if (false === $plain) {
            throw new \RuntimeException('Não foi possível decifrar (chave DATA_ENCRYPTION_KEY diferente da usada para cifrar?).');
        }

        return $plain;
    }

    private function key(): ?string
    {
        $key = base64_decode(trim($this->dataEncryptionKey), true);

        return false !== $key && \SODIUM_CRYPTO_SECRETBOX_KEYBYTES === \strlen($key) ? $key : null;
    }
}
