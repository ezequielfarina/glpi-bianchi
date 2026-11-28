<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Criptografia simétrica autenticada para dados sensíveis em repouso (ADR-0019).
 *
 * Formato atual (v2): `v2.<kid>.<base64url(nonce || ciphertext)>`
 *   - XChaCha20-Poly1305 IETF (libsodium), chave de 256 bits, nonce aleatório de 192 bits;
 *   - `kid` = 8 hex do SHA-256 da chave: identifica qual chave do chaveiro cifrou (rotação);
 *   - dado associado (AAD) = contexto (ex.: "license.key", "account.totp"): um valor cifrado copiado
 *     para outra coluna/registro não decifra — impede troca de ciphertext entre campos.
 * Legado (v1): `base64(nonce || secretbox)` XSalsa20-Poly1305 sem contexto — continua legível;
 * `app:crypto:rotate` regrava tudo em v2 com a chave atual.
 *
 * Chaves: DATA_ENCRYPTION_KEY = base64 de 32 bytes (a atual, cifra e decifra);
 * DATA_ENCRYPTION_KEYS_OLD = chaves anteriores separadas por vírgula (só decifram, durante a rotação).
 * Perder a chave = perder os dados cifrados: ela entra no backup (fora do CT).
 */
final class SecretBox
{
    private const string V2 = 'v2';

    /** @var array<string, string> kid => chave (a primeira é a atual) */
    private ?array $ring = null;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $dataEncryptionKey,
        #[\SensitiveParameter]
        private readonly string $dataEncryptionKeysOld = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return [] !== $this->ring();
    }

    /** kid da chave atual (para relatórios de rotação) */
    public function currentKeyId(): ?string
    {
        return array_key_first($this->ring());
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $context = ''): string
    {
        $ring = $this->ring();
        $kid = array_key_first($ring) ?? throw new \RuntimeException('DATA_ENCRYPTION_KEY não configurada: rode o deploy/lxc/install.sh para gerá-la.');
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::aad($context), $nonce, $ring[$kid]);

        return self::V2.'.'.$kid.'.'.sodium_bin2base64($nonce.$ct, \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function decrypt(string $encoded, string $context = ''): string
    {
        $ring = $this->ring();
        if ([] === $ring) {
            throw new \RuntimeException('DATA_ENCRYPTION_KEY não configurada.');
        }
        if (str_starts_with($encoded, self::V2.'.')) {
            $parts = explode('.', $encoded, 3);
            $key = $ring[$parts[1] ?? ''] ?? throw new \RuntimeException('Valor cifrado com uma chave que não está no chaveiro (DATA_ENCRYPTION_KEY / DATA_ENCRYPTION_KEYS_OLD).');
            try {
                $raw = sodium_base642bin($parts[2] ?? '', \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            } catch (\SodiumException) {
                throw new \RuntimeException('Valor cifrado inválido.');
            }
            $n = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            $plain = \strlen($raw) > $n ? sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $n), self::aad($context), substr($raw, 0, $n), $key) : false;
            if (false === $plain) {
                throw new \RuntimeException('Não foi possível decifrar (valor alterado ou fora do seu contexto).');
            }

            return $plain;
        }

        return $this->decryptLegacy($encoded, $ring);
    }

    /** Precisa ser regravado (formato antigo ou chave que não é a atual)? */
    public function needsRotation(string $encoded): bool
    {
        return !str_starts_with($encoded, self::V2.'.'.$this->currentKeyId().'.');
    }

    /** Decifra com o contexto informado e devolve cifrado de novo com a chave atual. */
    public function reencrypt(string $encoded, string $context = ''): string
    {
        return $this->encrypt($this->decrypt($encoded, $context), $context);
    }

    /**
     * Chave derivada para um uso específico (ex.: envelope de arquivos), a partir da chave atual
     * ou da chave `kid` — BLAKE2b (crypto_kdf), sem reaproveitar a chave mestra diretamente.
     */
    public function derive(string $purpose, ?string $kid = null): string
    {
        $ring = $this->ring();
        $kid ??= array_key_first($ring);
        $master = $ring[$kid ?? ''] ?? throw new \RuntimeException('Chave de criptografia indisponível.');

        return sodium_crypto_generichash($purpose, $master, \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }

    /** @param array<string, string> $ring */
    private function decryptLegacy(string $encoded, array $ring): string
    {
        $raw = base64_decode($encoded, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Valor cifrado inválido.');
        }
        foreach ($ring as $key) {
            $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
            if (false !== $plain) {
                return $plain;
            }
        }

        throw new \RuntimeException('Não foi possível decifrar (chave DATA_ENCRYPTION_KEY diferente da usada para cifrar?).');
    }

    private static function aad(string $context): string
    {
        return 'glpi-bianchi:'.$context;
    }

    /** @return array<string, string> */
    private function ring(): array
    {
        if (null !== $this->ring) {
            return $this->ring;
        }
        $ring = [];
        foreach ([$this->dataEncryptionKey, ...explode(',', $this->dataEncryptionKeysOld)] as $i => $encoded) {
            $key = base64_decode(trim($encoded), true);
            if (false !== $key && \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES === \strlen($key)) {
                $ring[substr(hash('sha256', $key), 0, 8)] ??= $key;
            } elseif (0 === $i) {
                // sem chave atual válida não cifra nada — nunca promove uma chave antiga a atual
                return $this->ring = [];
            }
        }

        return $this->ring = $ring;
    }
}
