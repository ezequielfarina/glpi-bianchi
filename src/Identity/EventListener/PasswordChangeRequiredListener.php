<?php

declare(strict_types=1);

namespace App\Identity\EventListener;

use App\Identity\Security\AuthenticatedAccount;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Conta com senha temporária (criada/resetada pelo administrador): até trocar a
 * senha, só pode consultar /me, trocar a senha e sair. Roda depois do firewall (8).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class PasswordChangeRequiredListener
{
    private const array ALLOWED = ['/api/ui/me', '/api/ui/me/password', '/api/ui/auth/logout'];

    public function __construct(private Security $security)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/ui')) {
            return;
        }
        $user = $this->security->getUser();
        if ($user instanceof AuthenticatedAccount && $user->mustChangePassword && !\in_array($request->getPathInfo(), self::ALLOWED, true)) {
            $r = new JsonResponse(['error' => 'Defina uma nova senha para continuar.', 'code' => 'password_change_required'], 403);
            $r->setEncodingOptions(\JSON_UNESCAPED_UNICODE);
            $event->setResponse($r);
        }
    }
}
