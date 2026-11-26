<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Ui\ReadModel\AgentQuery;
use App\Ui\ReadModel\AssetQuery;
use App\Ui\ReadModel\OverviewQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints de leitura da interface web (/api/ui/*), protegidos pelo mesmo
 * firewall da API (Bearer). Formato estável e "achatado", pensado para as telas;
 * integrações externas devem usar os recursos do API Platform (/api/assets...).
 */
#[AsController]
#[Route('/api/ui', name: 'ui_')]
final readonly class UiApiController
{
    public function __construct(
        private OverviewQuery $overview,
        private AssetQuery $assets,
        private AgentQuery $agents,
    ) {
    }

    #[Route('/overview', name: 'overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        return $this->json($this->overview->fetch());
    }

    #[Route('/assets', name: 'assets', methods: ['GET'])]
    public function assets(
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?string $type = null,
        #[MapQueryParameter] ?string $stale = null,
        #[MapQueryParameter] int $page = 1,
        #[MapQueryParameter] int $perPage = 50,
        #[MapQueryParameter] string $sort = 'name',
        #[MapQueryParameter] string $dir = 'asc',
    ): JsonResponse {
        return $this->json($this->assets->search($q, $type, $stale, $page, $perPage, $sort, $dir));
    }

    #[Route('/assets/{uuid}', name: 'asset', methods: ['GET'])]
    public function asset(string $uuid): JsonResponse
    {
        $detail = $this->assets->detail($uuid);

        return null === $detail
            ? $this->json(['error' => 'Ativo não encontrado'], Response::HTTP_NOT_FOUND)
            : $this->json($detail);
    }

    #[Route('/agents', name: 'agents', methods: ['GET'])]
    public function agents(): JsonResponse
    {
        return $this->json(['items' => $this->agents->agents()]);
    }

    #[Route('/inventories', name: 'inventories', methods: ['GET'])]
    public function inventories(#[MapQueryParameter] ?string $status = null): JsonResponse
    {
        return $this->json(['items' => $this->agents->inventories($status)]);
    }

    #[Route('/inventories/{id}/payload', name: 'inventory_payload', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function payload(string $id): Response
    {
        $payload = $this->agents->payload($id);

        return null === $payload
            ? $this->json(['error' => 'Inventário não encontrado'], Response::HTTP_NOT_FOUND)
            : new Response($payload, Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    /** Usado pela tela de login para validar o token. */
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        return $this->json(['authenticated' => true, 'server' => 'glpi-bianchi', 'version' => '0.1.0']);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
