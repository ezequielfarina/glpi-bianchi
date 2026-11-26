<?php

declare(strict_types=1);

namespace App\Ui\Write;

/** Recurso inexistente → HTTP 404 {"error": "..."}. */
final class NotFoundException extends \RuntimeException
{
}
