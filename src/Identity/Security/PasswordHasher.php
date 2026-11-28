<?php

declare(strict_types=1);

namespace App\Identity\Security;

/**
 * Hash de senha com **Argon2id** (libsodium, `sodium_crypto_pwhash_str`).
 *
 * Argon2id venceu a Password Hashing Competition e é a 1ª recomendação do
 * OWASP Password Storage Cheat Sheet / RFC 9106. Parâmetros padrão:
 * 64 MiB de memória, 3 iterações, 1 via — ~0,1–0,3 s por verificação num CT
 * de 2 vCPU, inviabilizando força bruta em GPU caso o banco vaze.
 * Sal aleatório de 128 bits e parâmetros ficam embutidos no próprio hash
 * (`$argon2id$v=19$m=65536,t=3,p=1$<sal>$<hash>`), o que permite rehash
 * transparente no login quando os parâmetros forem aumentados.
 *
 * Ambiente de teste usa parâmetros mínimos (config/services.yaml, when@test).
 */
final class PasswordHasher
{
    /** Senhas acima disso são recusadas antes do hash (mitiga DoS com payload enorme). */
    public const int MAX_BYTES = 1024;

    private ?string $dummy = null;

    public function __construct(
        private readonly int $opsLimit = 3,
        private readonly int $memLimitKib = 65536,
    ) {
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        if (\strlen($password) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Senha longa demais.');
        }

        return sodium_crypto_pwhash_str($password, $this->opsLimit, $this->memLimitKib * 1024);
    }

    public function verify(string $hash, #[\SensitiveParameter] string $password): bool
    {
        // senha vazia: libsodium emite warning "empty password" (virava erro 500 — auditoria 2026-11)
        if ('' === $password || \strlen($password) > self::MAX_BYTES || !str_starts_with($hash, '$argon2id$')) {
            return false;
        }

        return sodium_crypto_pwhash_str_verify($hash, $password);
    }

    public function needsRehash(string $hash): bool
    {
        return sodium_crypto_pwhash_str_needs_rehash($hash, $this->opsLimit, $this->memLimitKib * 1024);
    }

    /**
     * Gasta o mesmo tempo de uma verificação real quando o usuário não existe,
     * para que o tempo de resposta não revele quais logins são válidos.
     */
    public function dummyVerify(#[\SensitiveParameter] string $password): void
    {
        $this->dummy ??= $this->hash(random_bytes(16));
        $this->verify($this->dummy, $password);
    }
}
