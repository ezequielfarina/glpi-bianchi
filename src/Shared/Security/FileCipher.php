<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Cifra arquivos em disco em fluxo (ADR-0019): libsodium secretstream XChaCha20-Poly1305,
 * blocos de 64 KiB autenticados e encadeados — truncar, reordenar ou alterar qualquer bloco
 * falha na leitura. Cada arquivo tem chave própria (aleatória, 256 bits), guardada cifrada pelo
 * SecretBox no banco (envelope): girar a chave mestra regrava só a chave do arquivo, não o arquivo.
 *
 * Formato: "BNCF1\n" (6 bytes) || cabeçalho secretstream (24 bytes) || blocos (64 KiB + 17 bytes).
 */
final class FileCipher
{
    public const string MAGIC = "BNCF1\n";
    private const int CHUNK = 65536;

    public static function newKey(): string
    {
        return sodium_crypto_secretstream_xchacha20poly1305_keygen();
    }

    public static function isEncrypted(string $path): bool
    {
        $h = @fopen($path, 'r');
        if (false === $h) {
            return false;
        }
        $magic = fread($h, \strlen(self::MAGIC));
        fclose($h);

        return self::MAGIC === $magic;
    }

    /** Cifra `$src` em `$dst` (podem ser o mesmo caminho: grava num temporário e troca de forma atômica). */
    public static function encryptFile(string $src, string $dst, #[\SensitiveParameter] string $key): void
    {
        $tmp = $dst.'.enc-'.bin2hex(random_bytes(4));
        $in = fopen($src, 'r') ?: throw new \RuntimeException('Não foi possível ler '.$src);
        $out = fopen($tmp, 'w') ?: throw new \RuntimeException('Não foi possível gravar '.$tmp);
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($out, self::MAGIC.$header);
            $chunk = (string) fread($in, self::CHUNK);
            do {
                $next = feof($in) ? '' : (string) fread($in, self::CHUNK);
                $tag = '' === $next ? \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
                $chunk = $next;
            } while (\SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL !== $tag);
            fflush($out);
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        } finally {
            fclose($in);
        }
        fclose($out);
        @chmod($tmp, 0o640);
        if (!rename($tmp, $dst)) {
            @unlink($tmp);
            throw new \RuntimeException('Não foi possível gravar '.$dst);
        }
    }

    /**
     * Decifra em fluxo, entregando cada bloco a `$sink` (ex.: echo para a resposta HTTP).
     *
     * @param callable(string): void $sink
     */
    public static function decryptTo(string $path, #[\SensitiveParameter] string $key, callable $sink): void
    {
        $in = fopen($path, 'r') ?: throw new \RuntimeException('Arquivo de anexo ausente.');
        try {
            if (self::MAGIC !== fread($in, \strlen(self::MAGIC))) {
                throw new \RuntimeException('Arquivo não está no formato cifrado esperado.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull((string) fread($in, \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
            $final = false;
            while (!feof($in)) {
                $block = (string) fread($in, self::CHUNK + \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                if ('' === $block) {
                    break;
                }
                $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block);
                if (false === $res) {
                    throw new \RuntimeException('Anexo corrompido ou adulterado (falha de autenticação).');
                }
                [$plain, $tag] = $res;
                $sink($plain);
                if (\SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $tag) {
                    $final = true;
                    break;
                }
            }
            if (!$final) {
                throw new \RuntimeException('Anexo truncado (bloco final ausente).');
            }
        } finally {
            fclose($in);
        }
    }

    public static function decryptToString(string $path, #[\SensitiveParameter] string $key): string
    {
        $buf = '';
        self::decryptTo($path, $key, static function (string $p) use (&$buf): void { $buf .= $p; });

        return $buf;
    }
}
