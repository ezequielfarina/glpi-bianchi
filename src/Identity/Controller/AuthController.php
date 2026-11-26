<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Identity\Security\SessionManager;
use App\Identity\Service\LoginService;
use App\Ui\Controller\UiJson;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Login/2FA/logout do painel. Respostas de erro genéricas (não revelam se o login existe).
 * Limites: corpo JSON pequeno; bloqueio progressivo em LoginThrottle (HTTP 429 + Retry-After).
 */
#[AsController]
#[Route('/api/ui/auth', name: 'auth_')]
final readonly class AuthController
{
    use UiJson;

    public function __construct(
        private LoginService $login,
        private SessionManager $sessions,
    ) {
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $username = \is_string($body['username'] ?? null) ? $body['username'] : '';
        $password = \is_string($body['password'] ?? null) ? $body['password'] : '';
        $r = $this->login->login($username, $password, $request);

        return match ($r['status']) {
            'throttled' => $this->throttled($r['retry_after'] ?? 60),
            'invalid' => $this->json(['error' => 'Usuário ou senha inválidos.', 'code' => 'invalid_credentials'], Response::HTTP_UNAUTHORIZED),
            'mfa_required' => $this->withCookie($request, $this->json(['mfa_required' => true]), (string) $r['token']),
            default => $this->withCookie($request, $this->json(['ok' => true, 'must_change_password' => $r['must_change_password'] ?? false]), (string) $r['token']),
        };
    }

    #[Route('/mfa', name: 'mfa', methods: ['POST'])]
    public function mfa(Request $request): JsonResponse
    {
        $code = $this->body($request)['code'] ?? '';
        $r = $this->login->verifyMfa((string) $request->cookies->get(SessionManager::COOKIE, ''), \is_string($code) ? $code : '', $request);

        return match ($r['status']) {
            'expired' => $this->clear($request, $this->json(['error' => 'A verificação expirou. Entre com usuário e senha de novo.', 'code' => 'mfa_expired'], Response::HTTP_UNAUTHORIZED)),
            'invalid' => $this->json(['error' => 'Código inválido.', 'code' => 'invalid_code', 'remaining' => $r['remaining'] ?? 0], Response::HTTP_UNAUTHORIZED),
            default => $this->withCookie($request, $this->json(['ok' => true, 'must_change_password' => $r['must_change_password'] ?? false]), (string) $r['token']),
        };
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $this->login->logout((string) $request->cookies->get(SessionManager::COOKIE, ''), $request);

        return $this->clear($request, $this->json(['ok' => true]));
    }

    private function withCookie(Request $request, JsonResponse $response, string $token): JsonResponse
    {
        $response->headers->setCookie($this->sessions->cookie($request, $token));

        return $response;
    }

    private function clear(Request $request, JsonResponse $response): JsonResponse
    {
        $response->headers->setCookie($this->sessions->clearCookie($request));

        return $response;
    }

    private function throttled(int $seconds): JsonResponse
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        $r = $this->json(['error' => \sprintf('Muitas tentativas. Tente de novo em %d minuto(s).', $minutes), 'code' => 'throttled', 'retry_after' => $seconds], Response::HTTP_TOO_MANY_REQUESTS);
        $r->headers->set('Retry-After', (string) $seconds);

        return $r;
    }
}
