<?php

declare(strict_types=1);

namespace App\Ui\Write;

/**
 * Leitura validada de um corpo JSON da interface. Acumula erros por campo e lança
 * ValidationException em check(). Strings vazias viram NULL nos campos opcionais.
 *
 *   $p = new Payload($data);
 *   $name = $p->string('name', 255, required: true);
 *   $p->check();
 */
final class Payload
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    /** Valor bruto (sem validação) — use apenas para inspecionar estrutura. */
    public function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    public function string(string $key, int $max = 255, bool $required = false): ?string
    {
        $v = $this->data[$key] ?? null;
        if (null !== $v && !\is_scalar($v)) {
            $this->errors[$key] = 'Valor inválido.';

            return null;
        }
        $v = null === $v ? null : trim((string) $v);
        if (null === $v || '' === $v) {
            if ($required) {
                $this->errors[$key] = 'Campo obrigatório.';
            }

            return null;
        }
        if (mb_strlen($v) > $max) {
            $this->errors[$key] = \sprintf('Máximo de %d caracteres.', $max);

            return null;
        }

        return $v;
    }

    public function int(string $key, ?int $min = null, ?int $max = null, bool $required = false): ?int
    {
        $v = $this->data[$key] ?? null;
        if (null === $v || '' === $v) {
            if ($required) {
                $this->errors[$key] = 'Campo obrigatório.';
            }

            return null;
        }
        if (!\is_int($v) && !(\is_string($v) && preg_match('/^-?\d+$/', $v))) {
            $this->errors[$key] = 'Informe um número inteiro.';

            return null;
        }
        $v = (int) $v;
        if ((null !== $min && $v < $min) || (null !== $max && $v > $max)) {
            $this->errors[$key] = \sprintf('Valor deve estar entre %s e %s.', $min ?? '−∞', $max ?? '∞');

            return null;
        }

        return $v;
    }

    /** Valor monetário: aceita 1234.56, "1.234,56" ou "1234,56". Devolve string "1234.56". */
    public function money(string $key): ?string
    {
        $v = $this->data[$key] ?? null;
        if (null === $v || '' === $v) {
            return null;
        }
        if (\is_int($v) || \is_float($v)) {
            return number_format((float) $v, 2, '.', '');
        }
        if (!\is_string($v)) {
            $this->errors[$key] = 'Valor inválido.';

            return null;
        }
        $s = trim($v);
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        if (!is_numeric($s) || (float) $s < 0 || (float) $s > 999_999_999_999) {
            $this->errors[$key] = 'Valor monetário inválido.';

            return null;
        }

        return number_format((float) $s, 2, '.', '');
    }

    public function date(string $key, bool $required = false): ?string
    {
        $v = $this->string($key, 10, $required);
        if (null === $v) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (false === $d || $d->format('Y-m-d') !== $v) {
            $this->errors[$key] = 'Data inválida (use AAAA-MM-DD).';

            return null;
        }

        return $v;
    }

    /** @param list<string> $allowed */
    public function enum(string $key, array $allowed, ?string $default = null): ?string
    {
        $v = $this->string($key, 64);
        if (null === $v) {
            return $default;
        }
        if (!\in_array($v, $allowed, true)) {
            $this->errors[$key] = 'Opção inválida.';

            return $default;
        }

        return $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->data[$key] ?? null;

        return null === $v ? $default : filter_var($v, \FILTER_VALIDATE_BOOL);
    }

    public function email(string $key): ?string
    {
        $v = $this->string($key, 320);
        if (null !== $v && false === filter_var($v, \FILTER_VALIDATE_EMAIL)) {
            $this->errors[$key] = 'E-mail inválido.';

            return null;
        }

        return $v;
    }

    /**
     * URL clicável na interface: só http(s) com host. Bloqueia `javascript:`, `data:`, `vbscript:`…
     * (auditoria 2026-11: XSS armazenado pelo site do fornecedor). "empresa.com.br" vira "https://empresa.com.br".
     */
    public function url(string $key, int $max = 255): ?string
    {
        $v = $this->string($key, $max);
        if (null === $v) {
            return null;
        }
        if (1 !== preg_match('#^[a-z][a-z0-9+.-]*:#i', $v)) {
            $v = 'https://'.$v;
        }
        $scheme = strtolower((string) parse_url($v, \PHP_URL_SCHEME));
        $host = (string) parse_url($v, \PHP_URL_HOST);
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host || 1 === preg_match('/[\s<>"\'`]/', $v)) {
            $this->errors[$key] = 'Informe um endereço http(s), ex.: https://empresa.com.br';

            return null;
        }

        return $v;
    }

    /** Objeto chave→valor de strings curtas (atributos livres do ativo). @return array<string, string> */
    public function attributes(string $key, int $maxKeys = 30): array
    {
        $v = $this->data[$key] ?? [];
        if (!\is_array($v)) {
            $this->errors[$key] = 'Formato inválido.';

            return [];
        }
        $out = [];
        foreach ($v as $k => $val) {
            if (\count($out) >= $maxKeys) {
                break;
            }
            $k = self::attributeKey((string) $k);
            if ('' === $k || !\is_scalar($val) || '' === trim((string) $val)) {
                continue;
            }
            $out[$k] = mb_substr(trim((string) $val), 0, 255);
        }

        return $out;
    }

    /** "Contador páginas" → "contador_paginas" (ASCII, minúsculo, sublinhado). */
    public static function attributeKey(string $label): string
    {
        $ascii = class_exists(\Transliterator::class)
            ? (string) \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($label)
            : (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);

        return trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $ascii)), '_');
    }

    public function error(string $key, string $message): void
    {
        $this->errors[$key] = $message;
    }

    public function check(): void
    {
        if ([] !== $this->errors) {
            throw new ValidationException($this->errors);
        }
    }
}
