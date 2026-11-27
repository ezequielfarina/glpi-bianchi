<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Inventory\Entity\Agent;
use App\Inventory\Service\AgentWakeup;
use App\Settings\Service\Settings;
use App\Ui\ReadModel\AgentQuery;
use App\Ui\ReadModel\AssetQuery;
use App\Ui\ReadModel\OverviewQuery;
use Doctrine\ORM\EntityManagerInterface;
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
        private AgentWakeup $wakeup,
        private Settings $settings,
        private EntityManagerInterface $em,
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
        #[MapQueryParameter] ?string $branch = null,
        #[MapQueryParameter] ?string $stock = null,
    ): JsonResponse {
        $stock = \in_array($stock, ['all', 'only'], true) ? $stock : null;

        return $this->json($this->assets->search($q, $type, $stale, $page, $perPage, $sort, $dir, $branch, $stock));
    }

    /** Totais por tipo (contadores dos filtros); ?branch=id|none. */
    #[Route('/assets/summary', name: 'asset_summary', methods: ['GET'], priority: 10)]
    public function assetSummary(#[MapQueryParameter] ?string $branch = null): JsonResponse
    {
        return $this->json(['types' => $this->assets->typeSummary($branch)]);
    }

    #[Route('/assets/{uuid}', name: 'asset', methods: ['GET'])]
    public function asset(string $uuid): JsonResponse
    {
        $detail = $this->assets->detail($uuid);

        return null === $detail
            ? $this->json(['error' => 'Ativo não encontrado'], Response::HTTP_NOT_FOUND)
            : $this->json($detail);
    }

    #[Route('/agents', name: 'agents', methods: ['GET'], priority: 1)]
    public function agents(): JsonResponse
    {
        return $this->json(['items' => $this->agents->agents()]);
    }

    /** Forçar inventário: chama GET /now no servidor HTTP do GLPI Agent. */
    #[Route('/agents/{deviceId}/wakeup', name: 'agent_wakeup', methods: ['POST'])]
    public function agentWakeup(string $deviceId): JsonResponse
    {
        $agent = $this->em->getRepository(Agent::class)->findOneBy(['deviceId' => $deviceId]);
        if (null === $agent) {
            return $this->json(['error' => 'Agente não encontrado'], Response::HTTP_NOT_FOUND);
        }
        $result = $this->wakeup->now($agent);

        return $this->json($result, AgentWakeup::RESULT_OK === $result['result'] ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY);
    }

    /** Status do agente: GET /status no servidor HTTP do GLPI Agent. */
    #[Route('/agents/{deviceId}/status', name: 'agent_status', methods: ['GET'])]
    public function agentStatus(string $deviceId): JsonResponse
    {
        $agent = $this->em->getRepository(Agent::class)->findOneBy(['deviceId' => $deviceId]);
        if (null === $agent) {
            return $this->json(['error' => 'Agente não encontrado'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->wakeup->status($agent));
    }

    #[Route('/settings', name: 'settings', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        return $this->json(['items' => $this->settings->all()]);
    }

    #[Route('/settings', name: 'settings_update', methods: ['PUT', 'PATCH'])]
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $values = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }
        if (!\is_array($values)) {
            return $this->json(['error' => 'Envie um objeto {"chave": valor}'], Response::HTTP_BAD_REQUEST);
        }
        $errors = $this->settings->update($values);
        if ([] !== $errors) {
            return $this->json(['error' => 'Valores inválidos', 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['items' => $this->settings->all()]);
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

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
