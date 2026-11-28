<?php

declare(strict_types=1);

namespace App\Ui\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Respostas do painel (/api/ui) não vão para o cache em disco do navegador (auditoria 2026-11, forense):
 * `no-cache, private` ainda grava o JSON — chave de licença revelada, usuários, inventário — e ele fica
 * recuperável na máquina do usuário depois do logout. Fotos (miniaturas) mantêm o cache privado
 * definido no download do anexo.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class NoStoreListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api/ui')) {
            return;
        }
        $headers = $event->getResponse()->headers;
        if (str_starts_with((string) $headers->get('Content-Type'), 'image/')) {
            return;
        }
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Pragma', 'no-cache');
    }
}
