<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Ui\ReadModel\ManageQuery;
use App\Ui\Write\ContractWriter;
use App\Ui\Write\LicenseWriter;
use App\Ui\Write\LookupWriter;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\SupplierWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestão: cadastros auxiliares, fornecedores/contatos, contratos e licenças.
 * Leituras em ManageQuery; escritas nos *Writer (validação → 422 via UiExceptionListener).
 */
#[AsController]
#[Route('/api/ui', name: 'ui_manage_')]
final readonly class UiManageController
{
    use UiJson;

    public function __construct(
        private ManageQuery $query,
        private SupplierWriter $suppliers,
        private ContractWriter $contracts,
        private LicenseWriter $licenses,
        private LookupWriter $lookups,
        private LoggerInterface $logger,
    ) {
    }

    // ------------------------------------------------------------------ cadastros auxiliares

    #[Route('/lookups', name: 'lookups', methods: ['GET'])]
    public function lookups(): JsonResponse
    {
        return $this->json($this->query->lookups());
    }

    #[Route('/lookups/{kind}', name: 'lookup_create', methods: ['POST'], requirements: ['kind' => 'license_types|license_categories|contract_types'])]
    public function lookupCreate(string $kind, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->lookups->save($kind, null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/lookups/{kind}/{id}', name: 'lookup_update', methods: ['PUT', 'PATCH'], requirements: ['kind' => 'license_types|license_categories|contract_types', 'id' => '\d+'])]
    public function lookupUpdate(string $kind, int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->lookups->save($kind, $id, $this->body($request))]);
    }

    #[Route('/lookups/{kind}/{id}', name: 'lookup_delete', methods: ['DELETE'], requirements: ['kind' => 'license_types|license_categories|contract_types', 'id' => '\d+'])]
    public function lookupDelete(string $kind, int $id): JsonResponse
    {
        $this->lookups->delete($kind, $id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/alerts', name: 'alerts', methods: ['GET'])]
    public function alerts(): JsonResponse
    {
        return $this->json($this->query->alerts());
    }

    // ------------------------------------------------------------------ fornecedores

    #[Route('/suppliers', name: 'suppliers', methods: ['GET'])]
    public function suppliers(#[MapQueryParameter] ?string $q = null): JsonResponse
    {
        return $this->json(['items' => $this->query->suppliers($q)]);
    }

    #[Route('/suppliers/{id}', name: 'supplier', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function supplier(int $id): JsonResponse
    {
        return $this->json($this->query->supplier($id) ?? throw new NotFoundException('Fornecedor não encontrado.'));
    }

    #[Route('/suppliers', name: 'supplier_create', methods: ['POST'])]
    public function supplierCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->suppliers->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/suppliers/{id}', name: 'supplier_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function supplierUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->suppliers->save($id, $this->body($request))]);
    }

    #[Route('/suppliers/{id}', name: 'supplier_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function supplierDelete(int $id): JsonResponse
    {
        $this->suppliers->delete($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/suppliers/{id}/contacts', name: 'contact_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function contactCreate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->suppliers->saveContact(null, $id, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/contacts/{id}', name: 'contact_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function contactUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->suppliers->saveContact($id, null, $this->body($request))]);
    }

    #[Route('/contacts/{id}', name: 'contact_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function contactDelete(int $id): JsonResponse
    {
        $this->suppliers->deleteContact($id);

        return $this->json(['deleted' => true]);
    }

    // ------------------------------------------------------------------ contratos

    #[Route('/contracts', name: 'contracts', methods: ['GET'])]
    public function contracts(#[MapQueryParameter] ?string $status = null): JsonResponse
    {
        return $this->json(['items' => $this->query->contracts($status)]);
    }

    #[Route('/contracts/{id}', name: 'contract', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function contract(int $id): JsonResponse
    {
        return $this->json($this->query->contract($id) ?? throw new NotFoundException('Contrato não encontrado.'));
    }

    #[Route('/contracts', name: 'contract_create', methods: ['POST'])]
    public function contractCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->contracts->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/contracts/{id}', name: 'contract_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function contractUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->contracts->save($id, $this->body($request))]);
    }

    #[Route('/contracts/{id}', name: 'contract_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function contractDelete(int $id): JsonResponse
    {
        $this->contracts->delete($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/contracts/{id}/assets/{uuid}', name: 'contract_link', methods: ['PUT', 'POST'], requirements: ['id' => '\d+'])]
    public function contractLink(int $id, string $uuid): JsonResponse
    {
        $this->contracts->linkAsset($id, $uuid);

        return $this->json(['linked' => true]);
    }

    #[Route('/contracts/{id}/assets/{uuid}', name: 'contract_unlink', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function contractUnlink(int $id, string $uuid): JsonResponse
    {
        $this->contracts->unlinkAsset($id, $uuid);

        return $this->json(['linked' => false]);
    }

    // ------------------------------------------------------------------ licenças

    #[Route('/licenses', name: 'licenses', methods: ['GET'])]
    public function licenses(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?int $category = null): JsonResponse
    {
        return $this->json(['items' => $this->query->licenses($q, $category)]);
    }

    #[Route('/licenses/{id}', name: 'license', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function license(int $id): JsonResponse
    {
        return $this->json($this->query->license($id) ?? throw new NotFoundException('Licença não encontrada.'));
    }

    #[Route('/licenses', name: 'license_create', methods: ['POST'])]
    public function licenseCreate(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->licenses->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/licenses/{id}', name: 'license_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function licenseUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->licenses->save($id, $this->body($request))]);
    }

    #[Route('/licenses/{id}', name: 'license_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function licenseDelete(int $id): JsonResponse
    {
        $this->licenses->delete($id);

        return $this->json(['deleted' => true]);
    }

    /** Revela a chave (decifrada) — registrado em log para auditoria. */
    #[Route('/licenses/{id}/key', name: 'license_key', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function licenseKey(int $id, Request $request): JsonResponse
    {
        $key = $this->licenses->revealKey($id);
        $this->logger->notice('License key revealed', ['license_id' => $id, 'ip' => $request->getClientIp()]);

        return $this->json(['key' => $key]);
    }

    #[Route('/licenses/{id}/assignments', name: 'license_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function licenseAssign(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->licenses->assign($id, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/license-assignments/{id}', name: 'license_unassign', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function licenseUnassign(int $id): JsonResponse
    {
        $this->licenses->unassign($id);

        return $this->json(['deleted' => true]);
    }
}
