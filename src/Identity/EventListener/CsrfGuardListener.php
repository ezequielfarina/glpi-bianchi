<?php

declare(strict_types=1);

namespace App\Identity\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Defesa CSRF para a API do painel (cookie de sessão), em camadas:
 *
 * 1. cookie `SameSite=Strict` — o navegador não o envia em requisições vindas de outro site;
 * 2. escrita exige o cabeçalho `X-Requested-With: XMLHttpRequest` — formulário HTML não
 *    consegue enviá-lo e, vindo de outra origem, força preflight CORS (que não é liberado);
 * 3. se o navegador informar `Origin`, ele precisa ser o próprio servidor.
 *
 * Roda antes do firewall (prioridade 16 > 8): bloqueia inclusive o POST de login (login CSRF).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class CsrfGuardListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/ui') || $request->isMethodSafe()) {
            return;
        }
        $origin = $request->headers->get('Origin');
        $sameOrigin = null === $origin || 'null' !== $origin && rtrim($origin, '/') === $request->getSchemeAndHttpHost();
        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With') || !$sameOrigin) {
            $r = new JsonResponse(['error' => 'Requisição bloqueada (proteção CSRF). Recarregue a página.', 'code' => 'csrf'], 403);
            $r->setEncodingOptions(\JSON_UNESCAPED_UNICODE);
            $event->setResponse($r);
        }
    }
}
