<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Identity\ReadModel\AccountQuery;
use App\Identity\Security\AuthenticatedAccount;
use App\Identity\Security\CurrentActor;
use App\Identity\Service\AccountService;
use App\Ui\Controller\UiJson;
use App\Ui\Write\NotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/** "Minha conta": dados, troca de senha, 2FA e sessões do próprio usuário. */
#[AsController]
#[Route('/api/ui/me', name: 'me_')]
final readonly class MeController
{
    use UiJson;

    public function __construct(
        private CurrentActor $actor,
        private AccountQuery $query,
        private AccountService $accounts,
    ) {
    }

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $me = $this->me();

        return $this->json($this->query->me($me->id, $me->sessionId) ?? throw new NotFoundException('Conta não encontrada.'));
    }

    #[Route('/password', name: 'password', methods: ['POST'])]
    public function password(Request $request): JsonResponse
    {
        $me = $this->me();
        $b = $this->body($request);
        $this->accounts->changeOwnPassword($me->id, self::str($b, 'current_password'), self::str($b, 'new_password'), $me->sessionId, $request);

        return $this->json(['ok' => true]);
    }

    #[Route('/sessions', name: 'sessions', methods: ['GET'])]
    public function sessions(): JsonResponse
    {
        $me = $this->me();

        return $this->json(['items' => $this->query->sessions($me->id, $me->sessionId)]);
    }

    #[Route('/sessions/{id}', name: 'session_revoke', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function revokeSession(int $id): JsonResponse
    {
        $this->accounts->revokeOwnSession($this->me()->id, $id);

        return $this->json(['ok' => true]);
    }

    #[Route('/mfa/setup', name: 'mfa_setup', methods: ['POST'])]
    public function mfaSetup(): JsonResponse
    {
        return $this->json($this->accounts->startMfaSetup($this->me()->id));
    }

    #[Route('/mfa/enable', name: 'mfa_enable', methods: ['POST'])]
    public function mfaEnable(Request $request): JsonResponse
    {
        $this->accounts->confirmMfa($this->me()->id, self::str($this->body($request), 'code'), $request);

        return $this->json(['ok' => true]);
    }

    #[Route('/mfa/disable', name: 'mfa_disable', methods: ['POST'])]
    public function mfaDisable(Request $request): JsonResponse
    {
        $b = $this->body($request);
        $this->accounts->disableOwnMfa($this->me()->id, self::str($b, 'password'), self::str($b, 'code'), $request);

        return $this->json(['ok' => true]);
    }

    private function me(): AuthenticatedAccount
    {
        return $this->actor->account() ?? throw new NotFoundException('Sessão inválida.');
    }

    /** @param array<string, mixed> $b */
    private static function str(array $b, string $key): string
    {
        return \is_string($b[$key] ?? null) ? $b[$key] : '';
    }
}
