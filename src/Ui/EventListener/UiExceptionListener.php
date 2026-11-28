<?php

declare(strict_types=1);

namespace App\Ui\EventListener;

use App\Ui\Write\NotFoundException;
use App\Ui\Write\ValidationException;
use App\Wifi\Unifi\UnifiException;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Erros de domínio da interface → JSON padronizado:
 *   ValidationException → 422 {"error": "...", "errors": {campo: msg}}
 *   NotFoundException   → 404 {"error": "..."}
 *   erro de dado do PostgreSQL (SQLSTATE classe 22: número fora da faixa, texto inválido para o tipo…)
 *                       → 400 — parâmetro de URL absurdo não vira 500 (auditoria 2026-11, fuzzing)
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
            $e instanceof UnifiException => new JsonResponse(['error' => $e->getMessage(), 'code' => 'unifi'], 502),
            $e instanceof DriverException && str_starts_with((string) $e->getSQLState(), '22') => new JsonResponse(['error' => 'Parâmetro inválido.', 'code' => 'bad_parameter'], 400),
            default => null,
        };
        if (null !== $response) {
            $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE);
            $event->setResponse($response);
        }
    }
}
