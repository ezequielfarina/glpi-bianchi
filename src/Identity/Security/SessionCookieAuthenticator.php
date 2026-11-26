<?php

declare(strict_types=1);

namespace App\Identity\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Autentica /api/ui/* pelo cookie de sessão (firewall "ui", stateless do ponto de
 * vista do PHP: o estado está em `user_session`). Sem cookie válido → 401 JSON.
 */
final class SessionCookieAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly SessionManager $sessions)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->cookies->has(SessionManager::COOKIE);
    }

    public function authenticate(Request $request): Passport
    {
        $row = $this->sessions->find((string) $request->cookies->get(SessionManager::COOKIE));
        if (null === $row) {
            throw new CustomUserMessageAuthenticationException('Sessão expirada. Entre novamente.');
        }
        $account = AuthenticatedAccount::fromRow($row);

        return new SelfValidatingPassport(new UserBadge($account->username, static fn (): AuthenticatedAccount => $account));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // rota pública (login) com cookie velho: segue sem autenticar
        if (\in_array($request->attributes->get('_route'), ['auth_login', 'auth_mfa'], true)) {
            return null;
        }
        // não apaga o cookie: pode ser a etapa 2FA pendente (válida só para /auth/mfa);
        // cookie inválido é inofensivo — o estado que vale está em user_session
        return self::unauthorized($exception->getMessageKey());
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized('Faça login para continuar.');
    }

    private static function unauthorized(string $message): JsonResponse
    {
        $r = new JsonResponse(['error' => $message, 'code' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        $r->setEncodingOptions(\JSON_UNESCAPED_UNICODE);
        $r->headers->set('Cache-Control', 'no-store');

        return $r;
    }
}
