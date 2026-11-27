<?php

declare(strict_types=1);

namespace App\Circuit\Controller;

use App\Circuit\Service\CircuitService;
use App\Ui\Controller\UiJson;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/** Circuitos de operadora (ADR-0016 §6). Escrita auditada (rotas ui_circuit_*). */
#[AsController]
#[Route('/api/ui/circuits', name: 'ui_circuit_')]
final readonly class CircuitController
{
    use UiJson;

    public function __construct(private CircuitService $circuits)
    {
    }

    #[Route('/types', name: 'types', methods: ['GET'])]
    public function types(): JsonResponse
    {
        $status = array_map(static fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(CircuitService::STATUS), CircuitService::STATUS);

        return $this->json(['items' => $this->circuits->types(), 'status' => $status]);
    }

    #[Route('/types', name: 'type_create', methods: ['POST'])]
    public function typeCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->circuits->saveType(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/types/{id}', name: 'type_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function typeUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->circuits->saveType($id, $this->body($request))]);
    }

    #[Route('/types/{id}', name: 'type_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function typeDelete(int $id): JsonResponse
    {
        $this->circuits->deleteType($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?int $branch = null, #[MapQueryParameter] ?int $supplier = null,
        #[MapQueryParameter] ?string $status = null): JsonResponse
    {
        return $this->json(['items' => $this->circuits->list(['q' => $q, 'branch' => $branch, 'supplier' => $supplier, 'status' => $status])]);
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        return $this->json($this->circuits->detail($id));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->circuits->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->circuits->save($id, $this->body($request))]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->circuits->delete($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/{id}/terminations/{side}', name: 'termination_save', methods: ['PUT'], requirements: ['id' => '\d+', 'side' => 'A|Z'])]
    public function terminationSave(int $id, string $side, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->circuits->saveTermination($id, $side, $this->body($request))]);
    }

    #[Route('/terminations/{id}', name: 'termination_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function terminationDelete(int $id): JsonResponse
    {
        $this->circuits->deleteTermination($id);

        return $this->json(['deleted' => true]);
    }
}
