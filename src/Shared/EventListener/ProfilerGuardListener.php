<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ferramentas de depuração (/_profiler, /_wdt, /_error) só a partir da própria máquina.
 *
 * Auditoria 2026-11 (crítico): com APP_ENV=dev, `/_profiler/phpinfo` expunha sem login API_TOKEN,
 * DATA_ENCRYPTION_KEY, senha do banco e APP_SECRET, e os perfis guardavam o cookie de sessão de
 * quem usou o sistema (sequestro de sessão). Usa REMOTE_ADDR cru — não confia em X-Forwarded-For.
 * Roda antes do roteador (prioridade 4096), em qualquer ambiente.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
final class ProfilerGuardListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (1 !== preg_match('#^/(_profiler|_wdt|_error)(/|$)#', $request->getPathInfo())) {
            return;
        }
        $ip = (string) $request->server->get('REMOTE_ADDR', '');
        if (!\in_array($ip, ['127.0.0.1', '::1'], true)) {
            $event->setResponse(new Response('', Response::HTTP_NOT_FOUND));
        }
    }
}
