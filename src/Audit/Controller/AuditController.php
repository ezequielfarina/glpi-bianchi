<?php

declare(strict_types=1);

namespace App\Audit\Controller;

use App\Audit\Service\AuditQuery;
use App\Ui\Controller\UiJson;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/** Trilha de auditoria (ADR-0015) — só Administrador (regra ^/api/ui/audit do security.yaml). */
#[AsController]
#[Route('/api/ui/audit', name: 'audit_')]
final readonly class AuditController
{
    use UiJson;

    public function __construct(private AuditQuery $query)
    {
    }

    #[Route('/actions', name: 'actions', methods: ['GET'])]
    public function actions(
        #[MapQueryParameter]
        ?string $q = null,
        #[MapQueryParameter]
        ?string $module = null,
        #[MapQueryParameter]
        ?string $actor = null,
        #[MapQueryParameter(name: 'entity_type')]
        ?string $entityType = null,
        #[MapQueryParameter(name: 'entity_id')]
        ?string $entityId = null,
        #[MapQueryParameter]
        ?string $from = null,
        #[MapQueryParameter]
        ?string $to = null,
        #[MapQueryParameter]
        int $page = 1
    ): JsonResponse {
        return $this->json($this->query->list(['q' => $q, 'module' => $module, 'actor' => $actor, 'entity_type' => $entityType,
            'entity_id' => $entityId, 'from' => $from, 'to' => $to, 'page' => $page]));
    }
}
