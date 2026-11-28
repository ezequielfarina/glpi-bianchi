<?php

declare(strict_types=1);

namespace App\Wifi\Controller;

use App\Ui\Controller\UiJson;
use App\Ui\Write\ValidationException;
use App\Wifi\Service\ControllerService;
use App\Wifi\Service\IdentityService;
use App\Wifi\Service\SyncService;
use App\Wifi\Service\WifiQuery;
use App\Wifi\Service\WlanApplier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Acesso Wi-Fi por MAC no UniFi (ADR-0020). Controllers: só administrador (security.yaml);
 * identidades, sincronizar e aplicar: técnico; leitura: todos. Escrita auditada (rotas ui_wifi_*).
 */
#[AsController]
#[Route('/api/ui/wifi', name: 'ui_wifi_')]
final readonly class WifiController
{
    use UiJson;

    public function __construct(
        private ControllerService $controllers,
        private SyncService $sync,
        private WlanApplier $applier,
        private IdentityService $identities,
        private WifiQuery $query,
    ) {
    }

    #[Route('/choices', name: 'choices', methods: ['GET'])]
    public function choices(): JsonResponse
    {
        return $this->json($this->identities->choices());
    }

    #[Route('/tree', name: 'tree', methods: ['GET'])]
    public function tree(): JsonResponse
    {
        return $this->json(['items' => $this->query->tree()]);
    }

    // ---- controllers (admin) ----

    #[Route('/controllers', name: 'controllers', methods: ['GET'])]
    public function controllerList(): JsonResponse
    {
        return $this->json(['items' => $this->controllers->list()]);
    }

    #[Route('/controllers', name: 'controller_create', methods: ['POST'])]
    public function controllerCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->controllers->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/controllers/{id}', name: 'controller_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function controllerUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->controllers->save($id, $this->body($request))]);
    }

    #[Route('/controllers/{id}', name: 'controller_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function controllerDelete(int $id): JsonResponse
    {
        $this->controllers->delete($id);

        return $this->json(['deleted' => true]);
    }

    /** {base_url} → certificado apresentado e pin-sha256, para conferir e fixar */
    #[Route('/controllers/tls-inspect', name: 'controller_tls', methods: ['POST'])]
    public function controllerTls(Request $request): JsonResponse
    {
        $url = $this->body($request)['base_url'] ?? null;
        if (!\is_string($url) || '' === $url) {
            throw new ValidationException(['base_url' => 'Informe o endereço do controller.']);
        }

        return $this->json($this->controllers->inspectTls(rtrim($url, '/')));
    }

    #[Route('/controllers/{id}/test', name: 'controller_test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function controllerTest(int $id): JsonResponse
    {
        return $this->json($this->controllers->test($id));
    }

    /** {secret, username?} — testa no UniFi antes de gravar */
    #[Route('/controllers/{id}/credential', name: 'controller_credential', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function controllerCredential(int $id, Request $request): JsonResponse
    {
        return $this->json($this->controllers->updateCredential($id, $this->body($request)));
    }

    // ---- sincronizar e aplicar (técnico) ----

    #[Route('/sync/{id}', name: 'sync', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function sync(int $id): JsonResponse
    {
        return $this->json($this->sync->sync($id));
    }

    #[Route('/sites/{id}/sync', name: 'site_sync', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function siteSync(int $id): JsonResponse
    {
        return $this->json($this->sync->syncSite($id));
    }

    #[Route('/sites/{id}', name: 'site_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function siteUpdate(int $id, Request $request): JsonResponse
    {
        $this->query->linkSiteBranch($id, $this->body($request));

        return $this->json(['id' => $id]);
    }

    #[Route('/wlans/{id}', name: 'wlan', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function wlan(int $id): JsonResponse
    {
        return $this->json($this->query->wlan($id) + ['identities' => $this->identities->list(['wlan' => $id]), 'unknown' => $this->identities->unknown($id)]);
    }

    #[Route('/wlans/{id}/apply', name: 'wlan_apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function wlanApply(int $id): JsonResponse
    {
        return $this->json($this->applier->apply($id));
    }

    /** {confirm: nome exato da rede} — liga o filtro em modo permissão (admin) */
    #[Route('/wlans/{id}/filter', name: 'wlan_filter', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function wlanFilter(int $id, Request $request): JsonResponse
    {
        return $this->json($this->applier->enableFilter($id, (string) ($this->body($request)['confirm'] ?? '')));
    }

    /** identifica um MAC que já estava no filtro do UniFi */
    #[Route('/wlans/{id}/adopt', name: 'wlan_adopt', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function wlanAdopt(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->identities->adopt($id, $this->body($request))], Response::HTTP_CREATED);
    }

    // ---- identidades (técnico) ----

    #[Route('/identities', name: 'identities', methods: ['GET'])]
    public function identityList(
        #[MapQueryParameter]
        ?string $q = null,
        #[MapQueryParameter]
        ?int $wlan = null,
        #[MapQueryParameter]
        ?string $status = null,
        #[MapQueryParameter]
        ?string $state = null
    ): JsonResponse {
        return $this->json(['items' => $this->identities->list(['q' => $q, 'wlan' => $wlan, 'status' => \in_array($status, ['active', 'revoked'], true) ? $status : null,
            'state' => \array_key_exists((string) $state, IdentityService::STATES) ? $state : null])]);
    }

    #[Route('/identities/{id}', name: 'identity', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function identity(int $id): JsonResponse
    {
        return $this->json($this->identities->detail($id));
    }

    #[Route('/lookup', name: 'lookup', methods: ['GET'])]
    public function lookup(#[MapQueryParameter] string $mac = ''): JsonResponse
    {
        return $this->json($this->identities->lookup($mac));
    }

    /** body + {apply: true} aplica nas redes na hora */
    #[Route('/identities', name: 'identity_create', methods: ['POST'])]
    public function identityCreate(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $r = $this->identities->save(null, $body);

        return $this->json(['id' => $r['id'], 'applied' => $this->applyIf($body, $r['wlans'])], Response::HTTP_CREATED);
    }

    #[Route('/identities/{id}', name: 'identity_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function identityUpdate(int $id, Request $request): JsonResponse
    {
        $body = $this->body($request);
        $r = $this->identities->save($id, $body);

        return $this->json(['id' => $id, 'applied' => $this->applyIf($body, $r['wlans'])]);
    }

    #[Route('/identities/{id}/revoke', name: 'identity_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function identityRevoke(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $id, 'applied' => $this->applyIf($this->body($request), $this->identities->revoke($id))]);
    }

    #[Route('/identities/{id}', name: 'identity_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function identityDelete(int $id): JsonResponse
    {
        $this->identities->delete($id);

        return $this->json(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<int>            $wlans
     *
     * @return list<array<string, mixed>>
     */
    private function applyIf(array $body, array $wlans): array
    {
        if (true !== ($body['apply'] ?? false)) {
            return [];
        }
        $out = [];
        foreach (\array_slice($wlans, 0, 20) as $w) {
            try {
                $out[] = $this->applier->apply($w);
            } catch (ValidationException $e) {
                $out[] = ['wlan_id' => $w, 'changed' => false, 'added' => [], 'removed' => [], 'total' => 0, 'warnings' => [], 'error' => $e->getMessage()];
            }
        }

        return $out;
    }
}
