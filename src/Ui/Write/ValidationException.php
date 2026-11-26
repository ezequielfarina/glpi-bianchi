<?php

declare(strict_types=1);

namespace App\Ui\Write;

/** Erros de validação por campo → HTTP 422 {"error": "...", "errors": {campo: mensagem}}. */
final class ValidationException extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
