<?php

declare(strict_types=1);

namespace App\Vpn\Controller;

use App\Ui\Controller\UiJson;
use App\Ui\Write\ValidationException;
use App\Vpn\Import\FortiConfigParser;
use App\Vpn\Service\ImportService;
use App\Vpn\Service\TunnelService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Túneis VPN IPsec (ADR-0022). Leitura: todos; importar e documentar: técnico. Escrita auditada (rotas ui_vpn_*);
 * o texto da configuração colada (`config_text`) é mascarado na auditoria e nunca gravado.
 */
#[AsController]
#[Route('/api/ui/vpn', name: 'ui_vpn_')]
final readonly class VpnController
{
    use UiJson;

    public function __construct(private TunnelService $tunnels, private ImportService $import)
    {
    }

    #[Route('/choices', name: 'choices', methods: ['GET'])]
    public function choices(): JsonResponse
    {
        $map = static fn (array $a): array => array_map(static fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys($a), $a);

        return $this->json(['status' => $map(TunnelService::STATUS), 'topology' => $map(TunnelService::TOPOLOGY)]);
    }

    #[Route('/tunnels', name: 'tunnels', methods: ['GET'])]
    public function list(
        #[MapQueryParameter]
        ?string $q = null,
        #[MapQueryParameter]
        ?int $device = null,
        #[MapQueryParameter]
        ?int $branch = null,
        #[MapQueryParameter]
        ?string $status = null,
        #[MapQueryParameter]
        ?string $topology = null
    ): JsonResponse {
        return $this->json(['items' => $this->tunnels->list(['q' => $q, 'device' => $device, 'branch' => $branch,
            'status' => \array_key_exists((string) $status, TunnelService::STATUS) ? $status : null,
            'topology' => \array_key_exists((string) $topology, TunnelService::TOPOLOGY) ? $topology : null])]);
    }

    #[Route('/tunnels/{id}', name: 'tunnel', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        return $this->json($this->tunnels->detail($id));
    }

    #[Route('/tunnels/{id}', name: 'tunnel_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->tunnels->update($id, $this->body($request));

        return $this->json(['id' => $id]);
    }

    #[Route('/tunnels/{id}', name: 'tunnel_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->tunnels->delete($id);

        return $this->json(['deleted' => true]);
    }

    /** {device_id, config_text} → prévia sem gravar */
    #[Route('/import/preview', name: 'import_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        [$device, $text] = $this->importBody($request);

        return $this->json($this->import->preview($device, $text));
    }

    /** {device_id, config_text} → grava */
    #[Route('/import', name: 'import', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        [$device, $text] = $this->importBody($request);

        return $this->json($this->import->apply($device, $text));
    }

    /** @return array{0: int, 1: string} */
    private function importBody(Request $request): array
    {
        if (\strlen($request->getContent()) > FortiConfigParser::MAX_BYTES + 4096) {
            throw new ValidationException(['config_text' => 'Configuração grande demais (limite 8 MiB).']);
        }
        $body = $this->body($request);
        $device = $body['device_id'] ?? null;
        $text = $body['config_text'] ?? null;
        if (!\is_int($device) && !(\is_string($device) && ctype_digit($device))) {
            throw new ValidationException(['device_id' => 'Escolha o FortiGate (equipamento) de onde veio a configuração.']);
        }
        if (!\is_string($text)) {
            throw new ValidationException(['config_text' => 'Cole a configuração do FortiGate.']);
        }

        return [(int) $device, $text];
    }
}
