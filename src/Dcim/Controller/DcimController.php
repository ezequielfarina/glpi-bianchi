<?php

declare(strict_types=1);

namespace App\Dcim\Controller;

use App\Dcim\Service\CableService;
use App\Dcim\Service\CableTracer;
use App\Dcim\Service\CatalogService;
use App\Dcim\Service\DcimChoices;
use App\Dcim\Service\DeviceService;
use App\Dcim\Service\LocationService;
use App\Dcim\Service\RackService;
use App\Ui\Controller\UiJson;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Infraestrutura física (ADR-0016): locais, racks, tipos, equipamentos, portas, cabos e trace.
 * Leitura: qualquer perfil; escrita: técnico/admin (regra geral de /api/ui no security.yaml).
 * Toda escrita entra na trilha de auditoria (ADR-0015, rotas ui_dcim_*).
 */
#[AsController]
#[Route('/api/ui/dcim', name: 'ui_dcim_')]
final readonly class DcimController
{
    use UiJson;

    public function __construct(
        private LocationService $locations,
        private RackService $racks,
        private CatalogService $catalog,
        private DeviceService $devices,
        private CableService $cables,
        private CableTracer $tracer,
        private Refs $refs,
    ) {
    }

    #[Route('/choices', name: 'choices', methods: ['GET'])]
    public function choices(): JsonResponse
    {
        return $this->json(DcimChoices::all() + ['roles' => $this->catalog->roles()]);
    }

    // ------------------------------------------------------------------ locais

    #[Route('/locations', name: 'locations', methods: ['GET'])]
    public function locations(#[MapQueryParameter] ?int $branch = null): JsonResponse
    {
        return $this->json(['items' => $this->locations->tree($branch)]);
    }

    #[Route('/locations/{id}', name: 'location', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function location(int $id): JsonResponse
    {
        return $this->json($this->locations->detail($id));
    }

    #[Route('/locations', name: 'location_create', methods: ['POST'])]
    public function locationCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->locations->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/locations/{id}', name: 'location_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function locationUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->locations->save($id, $this->body($request))]);
    }

    #[Route('/locations/{id}', name: 'location_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function locationDelete(int $id): JsonResponse
    {
        $this->locations->delete($id);

        return $this->json(['deleted' => true]);
    }

    // ------------------------------------------------------------------ racks

    #[Route('/racks', name: 'racks', methods: ['GET'])]
    public function racks(#[MapQueryParameter] ?int $branch = null, #[MapQueryParameter] ?int $location = null, #[MapQueryParameter] ?string $q = null): JsonResponse
    {
        return $this->json(['items' => $this->racks->list($branch, $location, $q)]);
    }

    #[Route('/racks/{id}', name: 'rack', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function rack(int $id): JsonResponse
    {
        return $this->json($this->racks->detail($id));
    }

    #[Route('/racks', name: 'rack_create', methods: ['POST'])]
    public function rackCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->racks->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/racks/{id}', name: 'rack_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function rackUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->racks->save($id, $this->body($request))]);
    }

    #[Route('/racks/{id}', name: 'rack_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function rackDelete(int $id): JsonResponse
    {
        $this->racks->delete($id);

        return $this->json(['deleted' => true]);
    }

    // ------------------------------------------------------------------ funções e tipos

    #[Route('/roles', name: 'roles', methods: ['GET'])]
    public function roles(): JsonResponse
    {
        return $this->json(['items' => $this->catalog->roles()]);
    }

    #[Route('/roles', name: 'role_create', methods: ['POST'])]
    public function roleCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->catalog->saveRole(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/roles/{id}', name: 'role_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function roleUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->catalog->saveRole($id, $this->body($request))]);
    }

    #[Route('/roles/{id}', name: 'role_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function roleDelete(int $id): JsonResponse
    {
        $this->catalog->deleteRole($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/device-types', name: 'types', methods: ['GET'])]
    public function types(#[MapQueryParameter] ?string $q = null): JsonResponse
    {
        return $this->json(['items' => $this->catalog->types($q)]);
    }

    #[Route('/device-types/{id}', name: 'type', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function type(int $id): JsonResponse
    {
        return $this->json($this->catalog->type($id));
    }

    #[Route('/device-types', name: 'type_create', methods: ['POST'])]
    public function typeCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->catalog->saveType(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/device-types/{id}', name: 'type_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function typeUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->catalog->saveType($id, $this->body($request))]);
    }

    #[Route('/device-types/{id}', name: 'type_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function typeDelete(int $id): JsonResponse
    {
        $this->catalog->deleteType($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/device-types/{id}/templates', name: 'template_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function templateCreate(int $id, Request $request): JsonResponse
    {
        return $this->json(['ids' => $this->catalog->addTemplates($id, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/port-templates/{id}', name: 'template_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function templateUpdate(int $id, Request $request): JsonResponse
    {
        $this->catalog->updateTemplate($id, $this->body($request));

        return $this->json(['id' => $id]);
    }

    #[Route('/port-templates/{id}', name: 'template_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function templateDelete(int $id): JsonResponse
    {
        $this->catalog->deleteTemplate($id);

        return $this->json(['deleted' => true]);
    }

    // ------------------------------------------------------------------ equipamentos

    #[Route('/devices', name: 'devices', methods: ['GET'])]
    public function devices(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?int $branch = null, #[MapQueryParameter] ?int $location = null,
        #[MapQueryParameter] ?int $rack = null, #[MapQueryParameter] ?int $role = null, #[MapQueryParameter] ?int $type = null,
        #[MapQueryParameter] ?string $status = null): JsonResponse
    {
        return $this->json(['items' => $this->devices->list(['q' => $q, 'branch' => $branch, 'location' => $location, 'rack' => $rack, 'role' => $role,
            'type' => $type, 'status' => $status])]);
    }

    #[Route('/devices/{id}', name: 'device', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function device(int $id): JsonResponse
    {
        return $this->json($this->devices->detail($id));
    }

    #[Route('/devices', name: 'device_create', methods: ['POST'])]
    public function deviceCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->devices->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/devices/{id}', name: 'device_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function deviceUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->devices->save($id, $this->body($request))]);
    }

    #[Route('/devices/{id}', name: 'device_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deviceDelete(int $id): JsonResponse
    {
        $this->devices->delete($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/asset-candidates', name: 'asset_candidates', methods: ['GET'])]
    public function assetCandidates(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?int $branch = null): JsonResponse
    {
        return $this->json(['items' => $this->devices->assetCandidates($q, $branch)]);
    }

    /** Equipamento físico vinculado ao ativo do inventário (tela do ativo): local, rack e onde cada porta chega. */
    #[Route('/assets/{uuid}/device', name: 'asset_device', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function assetDevice(string $uuid): JsonResponse
    {
        $asset = $this->refs->asset($uuid) ?? throw new NotFoundException('Ativo não encontrado.');

        return $this->json(['device' => $this->devices->forAsset($asset['id'])]);
    }

    // ------------------------------------------------------------------ portas

    #[Route('/devices/{id}/ports', name: 'ports', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ports(int $id, #[MapQueryParameter] ?string $kind = null, #[MapQueryParameter] bool $free = false): JsonResponse
    {
        return $this->json(['items' => $this->devices->portOptions($id, $kind, $free)]);
    }

    #[Route('/devices/{id}/ports', name: 'port_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function portCreate(int $id, Request $request): JsonResponse
    {
        return $this->json(['ids' => $this->devices->addPorts($id, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/ports/{id}', name: 'port_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function portUpdate(int $id, Request $request): JsonResponse
    {
        $this->devices->updatePort($id, $this->body($request));

        return $this->json(['id' => $id]);
    }

    #[Route('/ports/{id}', name: 'port_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function portDelete(int $id): JsonResponse
    {
        return $this->json(['deleted' => $this->devices->deletePorts([$id])]);
    }

    /** Exclusão em lote: {"ids": [1, 2, 3]}. */
    #[Route('/ports/delete', name: 'port_bulk_delete', methods: ['POST'])]
    public function portBulkDelete(Request $request): JsonResponse
    {
        $ids = $this->body($request)['ids'] ?? null;
        if (!\is_array($ids) || [] === $ids || \count($ids) > 1024) {
            throw new ValidationException(['ids' => 'Informe as portas.']);
        }

        return $this->json(['deleted' => $this->devices->deletePorts(array_values(array_map('intval', array_filter($ids, 'is_numeric'))))]);
    }

    #[Route('/ports/{id}/trace', name: 'port_trace', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function portTrace(int $id): JsonResponse
    {
        return $this->json($this->tracer->trace('port', $id));
    }

    #[Route('/circuit-terminations/{id}/trace', name: 'circuit_trace', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function circuitTrace(int $id): JsonResponse
    {
        return $this->json($this->tracer->trace('circuit', $id));
    }

    // ------------------------------------------------------------------ cabos

    #[Route('/cables', name: 'cables', methods: ['GET'])]
    public function cables(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?int $branch = null, #[MapQueryParameter] ?int $device = null,
        #[MapQueryParameter] ?string $type = null, #[MapQueryParameter] ?string $status = null): JsonResponse
    {
        return $this->json(['items' => $this->cables->list(['q' => $q, 'branch' => $branch, 'device' => $device, 'type' => $type, 'status' => $status])]);
    }

    #[Route('/cables/{id}', name: 'cable', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function cable(int $id): JsonResponse
    {
        return $this->json($this->cables->detail($id));
    }

    #[Route('/cables', name: 'cable_create', methods: ['POST'])]
    public function cableCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->cables->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/cables/{id}', name: 'cable_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function cableUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->cables->save($id, $this->body($request))]);
    }

    #[Route('/cables/{id}', name: 'cable_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function cableDelete(int $id): JsonResponse
    {
        $this->cables->delete($id);

        return $this->json(['deleted' => true]);
    }
}
