<?php

declare(strict_types=1);

namespace App\Ui\EventListener;

use App\Ui\Write\NotFoundException;
use App\Ui\Write\ValidationException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Erros de domínio da interface → JSON padronizado:
 *   ValidationException → 422 {"error": "...", "errors": {campo: msg}}
 *   NotFoundException   → 404 {"error": "..."}
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final class UiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/ui')) {
            return;
        }
        $e = $event->getThrowable();
        $response = match (true) {
            $e instanceof ValidationException => new JsonResponse(['error' => 'Verifique os campos destacados.', 'errors' => $e->errors], 422),
            $e instanceof NotFoundException => new JsonResponse(['error' => $e->getMessage()], 404),
            default => null,
        };
        if (null !== $response) {
            $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE);
            $event->setResponse($response);
        }
    }
}
