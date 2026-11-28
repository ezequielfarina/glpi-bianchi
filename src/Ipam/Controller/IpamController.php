<?php

declare(strict_types=1);

namespace App\Ipam\Controller;

use App\Ipam\Service\IpAddressService;
use App\Ipam\Service\PrefixService;
use App\Ipam\Service\VlanService;
use App\Ui\Controller\UiJson;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/** IPAM (ADR-0016 §7): VRF, prefixos, faixas, IPs e VLANs. Escrita auditada (rotas ui_ipam_*). */
#[AsController]
#[Route('/api/ui/ipam', name: 'ui_ipam_')]
final readonly class IpamController
{
    use UiJson;

    public function __construct(
        private PrefixService $prefixes,
        private IpAddressService $ips,
        private VlanService $vlans,
    ) {
    }

    #[Route('/choices', name: 'choices', methods: ['GET'])]
    public function choices(): JsonResponse
    {
        $map = static fn (array $a): array => array_map(static fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys($a), $a);

        return $this->json([
            'prefix_status' => $map(PrefixService::STATUS), 'range_status' => $map(PrefixService::RANGE_STATUS),
            'ip_status' => $map(IpAddressService::STATUS), 'ip_roles' => $map(IpAddressService::ROLES), 'vlan_status' => $map(VlanService::VLAN_STATUS),
            'vlan_groups' => $this->vlans->groups(),
        ]);
    }

    // ------------------------------------------------------------------ prefixos

    #[Route('/prefixes', name: 'prefixes', methods: ['GET'])]
    public function prefixes(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?int $branch = null,
        #[MapQueryParameter] ?string $status = null, #[MapQueryParameter] ?int $family = null, #[MapQueryParameter] ?string $within = null): JsonResponse
    {
        return $this->json(['items' => $this->prefixes->list(['q' => $q, 'branch' => $branch, 'status' => $status, 'family' => $family, 'within' => $within])]);
    }

    #[Route('/prefixes/{id}', name: 'prefix', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function prefix(int $id): JsonResponse
    {
        return $this->json($this->prefixes->detail($id));
    }

    #[Route('/prefixes/{id}/next-ip', name: 'prefix_next_ip', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function prefixNextIp(int $id): JsonResponse
    {
        return $this->json(['address' => $this->prefixes->nextIp($id)]);
    }

    #[Route('/prefixes', name: 'prefix_create', methods: ['POST'])]
    public function prefixCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->prefixes->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/prefixes/{id}', name: 'prefix_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function prefixUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->prefixes->save($id, $this->body($request))]);
    }

    #[Route('/prefixes/{id}', name: 'prefix_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function prefixDelete(int $id): JsonResponse
    {
        $this->prefixes->delete($id);

        return $this->json(['deleted' => true]);
    }

    // ------------------------------------------------------------------ faixas

    #[Route('/ranges', name: 'ranges', methods: ['GET'])]
    public function ranges(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?string $status = null): JsonResponse
    {
        return $this->json(['items' => $this->prefixes->ranges(['q' => $q, 'status' => $status])]);
    }

    #[Route('/ranges/{id}/next-ip', name: 'range_next_ip', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function rangeNextIp(int $id): JsonResponse
    {
        return $this->json(['address' => $this->prefixes->nextIpInRange($id)]);
    }

    #[Route('/ranges', name: 'range_create', methods: ['POST'])]
    public function rangeCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->prefixes->saveRange(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/ranges/{id}', name: 'range_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function rangeUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->prefixes->saveRange($id, $this->body($request))]);
    }

    #[Route('/ranges/{id}', name: 'range_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function rangeDelete(int $id): JsonResponse
    {
        $this->prefixes->deleteRange($id);

        return $this->json(['deleted' => true]);
    }

    // ------------------------------------------------------------------ IPs

    #[Route('/ip-addresses', name: 'ips', methods: ['GET'])]
    public function ips(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?string $status = null,
        #[MapQueryParameter] ?string $role = null, #[MapQueryParameter] ?int $branch = null, #[MapQueryParameter] ?int $device = null,
        #[MapQueryParameter] ?bool $assigned = null): JsonResponse
    {
        return $this->json(['items' => $this->ips->list(['q' => $q, 'status' => $status, 'role' => $role, 'branch' => $branch,
            'device' => $device, 'assigned' => $assigned])]);
    }

    #[Route('/ip-addresses/{id}', name: 'ip', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ip(int $id): JsonResponse
    {
        return $this->json($this->ips->detail($id));
    }

    #[Route('/ip-addresses', name: 'ip_create', methods: ['POST'])]
    public function ipCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->ips->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/ip-addresses/{id}', name: 'ip_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function ipUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->ips->save($id, $this->body($request))]);
    }

    #[Route('/ip-addresses/{id}', name: 'ip_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function ipDelete(int $id): JsonResponse
    {
        $this->ips->delete($id);

        return $this->json(['deleted' => true]);
    }

    // ------------------------------------------------------------------ VRF, grupos, VLANs

    #[Route('/vlan-groups', name: 'vlan_groups', methods: ['GET'])]
    public function vlanGroups(): JsonResponse
    {
        return $this->json(['items' => $this->vlans->groups()]);
    }

    #[Route('/vlan-groups', name: 'vlan_group_create', methods: ['POST'])]
    public function vlanGroupCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->vlans->saveGroup(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/vlan-groups/{id}', name: 'vlan_group_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function vlanGroupUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->vlans->saveGroup($id, $this->body($request))]);
    }

    #[Route('/vlan-groups/{id}', name: 'vlan_group_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function vlanGroupDelete(int $id): JsonResponse
    {
        $this->vlans->deleteGroup($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/vlans', name: 'vlans', methods: ['GET'])]
    public function vlanList(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?int $group = null, #[MapQueryParameter] ?int $branch = null,
        #[MapQueryParameter] ?string $status = null): JsonResponse
    {
        return $this->json(['items' => $this->vlans->vlans(['q' => $q, 'group' => $group, 'branch' => $branch, 'status' => $status])]);
    }

    #[Route('/vlans/{id}', name: 'vlan', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function vlan(int $id): JsonResponse
    {
        return $this->json($this->vlans->vlan($id));
    }

    #[Route('/vlans', name: 'vlan_create', methods: ['POST'])]
    public function vlanCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->vlans->saveVlan(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/vlans/{id}', name: 'vlan_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function vlanUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->vlans->saveVlan($id, $this->body($request))]);
    }

    #[Route('/vlans/{id}', name: 'vlan_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function vlanDelete(int $id): JsonResponse
    {
        $this->vlans->deleteVlan($id);

        return $this->json(['deleted' => true]);
    }
}
