<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Identity\ReadModel\AccountQuery;
use App\Identity\Security\CurrentActor;
use App\Identity\Service\AccountService;
use App\Ui\Controller\UiJson;
use App\Ui\Write\NotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/** Administração de usuários e trilha de auditoria — somente ROLE_ADMIN (security.yaml). */
#[AsController]
#[Route('/api/ui', name: 'admin_')]
final readonly class AccountAdminController
{
    use UiJson;

    public function __construct(
        private AccountQuery $query,
        private AccountService $accounts,
        private CurrentActor $actor,
    ) {
    }

    #[Route('/users', name: 'users', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['items' => $this->query->list()]);
    }

    #[Route('/users', name: 'user_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->json($this->accounts->create($this->body($request), $this->actor->name(), $request), Response::HTTP_CREATED);
    }

    #[Route('/users/{id}', name: 'user_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->accounts->update($id, $this->body($request), $this->actorId(), $this->actor->name(), $request);

        return $this->json(['ok' => true]);
    }

    #[Route('/users/{id}', name: 'user_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $this->accounts->delete($id, $this->actorId(), $this->actor->name(), $request);

        return $this->json(['deleted' => true]);
    }

    #[Route('/users/{id}/reset-password', name: 'user_reset_password', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resetPassword(int $id, Request $request): JsonResponse
    {
        return $this->json(['temporary_password' => $this->accounts->resetPassword($id, $this->actor->name(), $request)]);
    }

    #[Route('/users/{id}/reset-mfa', name: 'user_reset_mfa', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resetMfa(int $id, Request $request): JsonResponse
    {
        $this->accounts->resetMfa($id, $this->actor->name(), $request);

        return $this->json(['ok' => true]);
    }

    #[Route('/users/{id}/unlock', name: 'user_unlock', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unlock(int $id, Request $request): JsonResponse
    {
        $this->accounts->unlock($id, $this->actor->name(), $request);

        return $this->json(['ok' => true]);
    }

    #[Route('/users/{id}/sessions', name: 'user_sessions', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sessions(int $id): JsonResponse
    {
        return $this->json(['items' => $this->query->sessions($id)]);
    }

    #[Route('/users/{id}/sessions', name: 'user_sessions_revoke', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function revokeSessions(int $id, Request $request): JsonResponse
    {
        return $this->json(['revoked' => $this->accounts->revokeSessions($id, $this->actor->name(), $request)]);
    }

    #[Route('/audit', name: 'audit', methods: ['GET'])]
    public function audit(
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?string $event = null,
        #[MapQueryParameter] int $page = 1,
    ): JsonResponse {
        return $this->json($this->query->audit($q, $event, $page));
    }

    private function actorId(): int
    {
        return ($this->actor->account() ?? throw new NotFoundException('Sessão inválida.'))->id;
    }
}
