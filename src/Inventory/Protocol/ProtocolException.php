<?php

declare(strict_types=1);

namespace App\Inventory\Protocol;

/** Erro de protocolo devolvido ao agente no formato que ele entende (JSON/XML). */
final class ProtocolException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatus = 400, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
