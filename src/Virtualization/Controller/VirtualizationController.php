<?php

declare(strict_types=1);

namespace App\Virtualization\Controller;

use App\Ui\Controller\UiJson;
use App\Ui\Write\ValidationException;
use App\Virtualization\Service\ClusterService;
use App\Virtualization\Service\VmService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/** Virtualização (ADR-0017): clusters, hosts, VMs, interfaces e discos. Escrita auditada (rotas ui_virt_*). */
#[AsController]
#[Route('/api/ui/virtualization', name: 'ui_virt_')]
final readonly class VirtualizationController
{
    use UiJson;

    public function __construct(private ClusterService $clusters, private VmService $vms)
    {
    }

    #[Route('/choices', name: 'choices', methods: ['GET'])]
    public function choices(): JsonResponse
    {
        $map = static fn (array $a): array => array_map(static fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys($a), $a);

        return $this->json(['cluster_status' => $map(ClusterService::STATUS), 'vm_status' => $map(VmService::STATUS),
            'types' => $this->clusters->types(), 'groups' => $this->clusters->groups()]);
    }

    /** {kind: "type"|"group"} */
    #[Route('/lookups/{kind}', name: 'lookup_create', methods: ['POST'], requirements: ['kind' => 'type|group'])]
    public function lookupCreate(string $kind, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->clusters->saveLookup($kind, null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/lookups/{kind}/{id}', name: 'lookup_update', methods: ['PUT'], requirements: ['kind' => 'type|group', 'id' => '\d+'])]
    public function lookupUpdate(string $kind, int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->clusters->saveLookup($kind, $id, $this->body($request))]);
    }

    #[Route('/lookups/{kind}/{id}', name: 'lookup_delete', methods: ['DELETE'], requirements: ['kind' => 'type|group', 'id' => '\d+'])]
    public function lookupDelete(string $kind, int $id): JsonResponse
    {
        $this->clusters->deleteLookup($kind, $id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/clusters', name: 'clusters', methods: ['GET'])]
    public function clusterList(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?int $branch = null, #[MapQueryParameter] ?int $type = null,
        #[MapQueryParameter] ?int $group = null): JsonResponse
    {
        return $this->json(['items' => $this->clusters->list(['q' => $q, 'branch' => $branch, 'type' => $type, 'group' => $group])]);
    }

    #[Route('/clusters/{id}', name: 'cluster', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function cluster(int $id): JsonResponse
    {
        return $this->json($this->clusters->detail($id));
    }

    #[Route('/clusters', name: 'cluster_create', methods: ['POST'])]
    public function clusterCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->clusters->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/clusters/{id}', name: 'cluster_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function clusterUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->clusters->save($id, $this->body($request))]);
    }

    #[Route('/clusters/{id}', name: 'cluster_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function clusterDelete(int $id): JsonResponse
    {
        $this->clusters->delete($id);

        return $this->json(['deleted' => true]);
    }

    /** {device_id, member: true|false} */
    #[Route('/clusters/{id}/hosts', name: 'cluster_host', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function clusterHost(int $id, Request $request): JsonResponse
    {
        $b = $this->body($request);
        $device = $b['device_id'] ?? null;
        if (!is_numeric($device)) {
            throw new ValidationException(['device_id' => 'Escolha o servidor.']);
        }
        $this->clusters->setHost($id, (int) $device, (bool) ($b['member'] ?? true));

        return $this->json(['id' => $id]);
    }

    #[Route('/vms', name: 'vms', methods: ['GET'])]
    public function vmList(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?int $cluster = null, #[MapQueryParameter] ?int $host = null,
        #[MapQueryParameter] ?int $branch = null, #[MapQueryParameter] ?string $status = null): JsonResponse
    {
        return $this->json(['items' => $this->vms->list(['q' => $q, 'cluster' => $cluster, 'host' => $host, 'branch' => $branch, 'status' => $status])]);
    }

    #[Route('/vms/{id}', name: 'vm', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function vm(int $id): JsonResponse
    {
        return $this->json($this->vms->detail($id));
    }

    #[Route('/vms', name: 'vm_create', methods: ['POST'])]
    public function vmCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->vms->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/vms/{id}', name: 'vm_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function vmUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->vms->save($id, $this->body($request))]);
    }

    #[Route('/vms/{id}', name: 'vm_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function vmDelete(int $id): JsonResponse
    {
        $this->vms->delete($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/asset-candidates', name: 'asset_candidates', methods: ['GET'])]
    public function assetCandidates(#[MapQueryParameter] ?string $q = null): JsonResponse
    {
        return $this->json(['items' => $this->vms->assetCandidates($q)]);
    }

    #[Route('/vms/{id}/interfaces', name: 'iface_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ifaceCreate(int $id, Request $request): JsonResponse
    {
        return $this->json(['ids' => $this->vms->addInterfaces($id, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/interfaces/{id}', name: 'iface_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function ifaceUpdate(int $id, Request $request): JsonResponse
    {
        $this->vms->updateInterface($id, $this->body($request));

        return $this->json(['id' => $id]);
    }

    #[Route('/interfaces/{id}', name: 'iface_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function ifaceDelete(int $id): JsonResponse
    {
        $this->vms->deleteInterface($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/vms/{id}/disks', name: 'disk_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function diskCreate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->vms->saveDisk($id, null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/vms/{vm}/disks/{id}', name: 'disk_update', methods: ['PUT'], requirements: ['vm' => '\d+', 'id' => '\d+'])]
    public function diskUpdate(int $vm, int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->vms->saveDisk($vm, $id, $this->body($request))]);
    }

    #[Route('/disks/{id}', name: 'disk_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function diskDelete(int $id): JsonResponse
    {
        $this->vms->deleteDisk($id);

        return $this->json(['deleted' => true]);
    }
}
