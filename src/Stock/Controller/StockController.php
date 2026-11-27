<?php

declare(strict_types=1);

namespace App\Stock\Controller;

use App\Identity\Security\CurrentActor;
use App\Stock\Service\InvoiceService;
use App\Stock\Service\ShippingLabelDocument;
use App\Stock\Service\StockCatalog;
use App\Stock\Service\StockLedger;
use App\Stock\Service\StockQuery;
use App\Ui\Controller\UiJson;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Estoque central de TI e notas fiscais (ADR-0014). Leitura: qualquer perfil; escrita: técnico/admin
 * (regra geral de /api/ui no security.yaml).
 */
#[AsController]
#[Route('/api/ui/stock', name: 'ui_stock_')]
final readonly class StockController
{
    use UiJson;

    public function __construct(
        private CurrentActor $actor,
        private StockQuery $query,
        private StockCatalog $catalog,
        private StockLedger $ledger,
        private InvoiceService $invoices,
        private LoggerInterface $logger,
        private ShippingLabelDocument $label,
    ) {
    }

    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        return $this->json($this->query->summary());
    }

    #[Route('/low-count', name: 'low_count', methods: ['GET'])]
    public function lowCount(): JsonResponse
    {
        return $this->json(['low' => $this->query->lowCount()]);
    }

    // ------------------------------------------------------------------ itens

    #[Route('/items', name: 'items', methods: ['GET'])]
    public function items(#[MapQueryParameter] ?string $q = null, #[MapQueryParameter] ?string $category = null,
        #[MapQueryParameter] bool $low = false, #[MapQueryParameter] bool $inactive = false): JsonResponse
    {
        return $this->json(['items' => $this->query->items($q, $category, $low, $inactive)]);
    }

    #[Route('/items/{id}', name: 'item', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function item(int $id): JsonResponse
    {
        return $this->json($this->query->item($id));
    }

    #[Route('/items', name: 'item_create', methods: ['POST'])]
    public function itemCreate(Request $request): JsonResponse
    {
        $id = $this->catalog->save(null, $this->body($request));
        $this->audit('stock.item.created', ['id' => $id]);

        return $this->json(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/items/{id}', name: 'item_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function itemUpdate(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->catalog->save($id, $this->body($request))]);
    }

    #[Route('/items/{id}', name: 'item_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function itemDelete(int $id): JsonResponse
    {
        $result = $this->catalog->delete($id);
        $this->audit('stock.item.'.$result, ['id' => $id]);

        return $this->json(['result' => $result]);
    }

    // ------------------------------------------------------------------ movimentações

    #[Route('/movements', name: 'movements', methods: ['GET'])]
    public function movements(#[MapQueryParameter] ?int $item = null, #[MapQueryParameter] ?int $branch = null,
        #[MapQueryParameter] ?string $kind = null, #[MapQueryParameter] ?string $from = null, #[MapQueryParameter] ?string $to = null,
        #[MapQueryParameter] int $limit = 200): JsonResponse
    {
        return $this->json($this->query->movements(['item' => $item, 'branch' => $branch, 'kind' => $kind, 'from' => $from, 'to' => $to, 'limit' => $limit]));
    }

    #[Route('/consumption', name: 'consumption', methods: ['GET'])]
    public function consumption(#[MapQueryParameter] ?string $from = null, #[MapQueryParameter] ?string $to = null): JsonResponse
    {
        return $this->json($this->query->consumption($from, $to));
    }

    /** Saída para filial (operação) — dados da etiqueta de envio. */
    #[Route('/shipments/{batch}', name: 'shipment', methods: ['GET'])]
    public function shipment(string $batch): JsonResponse
    {
        return $this->json($this->query->shipment($batch));
    }

    /**
     * Etiqueta da saída em .odt (modelo Entrega_TI) para imprimir pelo LibreOffice. Os campos da tela da etiqueta
     * (ajustados pelo usuário) vêm na query; sem eles, usa os dados gravados na saída.
     */
    #[Route('/shipments/{batch}/label.odt', name: 'shipment_label', methods: ['GET'])]
    public function shipmentLabel(string $batch, #[MapQueryParameter] ?string $sender = null, #[MapQueryParameter] ?string $destination = null,
        #[MapQueryParameter] ?string $department = null, #[MapQueryParameter] ?string $attention = null): Response
    {
        $s = $this->query->shipment($batch);
        $branch = mb_strtoupper((string) $s['branch']);
        $code = mb_strtoupper((string) ($s['branch_code'] ?? ''));
        $dest = $destination ?? ('' !== $code && !str_contains($branch, $code) ? "$branch - $code" : $branch);
        $odt = $this->label->render([
            'sender' => mb_substr($sender ?? 'TI', 0, 60),
            'destination' => mb_substr($dest, 0, 80),
            'department' => mb_substr($department ?? (string) ($s['department'] ?? ''), 0, 64),
            'attention' => mb_substr($attention ?? (string) ($s['recipient'] ?? ''), 0, 80),
        ]);
        $name = 'etiqueta-'.trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $dest) ?: 'filial')), '-').'.odt';

        return new Response($odt, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.oasis.opendocument.text',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    #[Route('/in', name: 'in', methods: ['POST'])]
    public function manualIn(Request $request): JsonResponse
    {
        $r = $this->ledger->manualIn($this->body($request), $this->actor->name());
        $this->audit('stock.in', $r);

        return $this->json($r, Response::HTTP_CREATED);
    }

    #[Route('/out', name: 'out', methods: ['POST'])]
    public function out(Request $request): JsonResponse
    {
        $r = $this->ledger->dispatch($this->body($request), $this->actor->name());
        $this->audit('stock.out', $r);

        return $this->json($r, Response::HTTP_CREATED);
    }

    #[Route('/adjust', name: 'adjust', methods: ['POST'])]
    public function adjust(Request $request): JsonResponse
    {
        $data = $this->body($request);
        $this->ledger->adjust($data, $this->actor->name());
        $this->audit('stock.adjust', ['item' => $data['stock_item_id'] ?? null]);

        return $this->json(['ok' => true], Response::HTTP_CREATED);
    }

    // ------------------------------------------------------------------ notas fiscais

    #[Route('/invoices', name: 'invoices', methods: ['GET'])]
    public function invoices(#[MapQueryParameter] ?string $status = null, #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?int $supplier = null): JsonResponse
    {
        return $this->json(['items' => $this->invoices->list($status, $q, $supplier)]);
    }

    #[Route('/invoices/{id}', name: 'invoice', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function invoice(int $id): JsonResponse
    {
        return $this->json($this->invoices->detail($id));
    }

    #[Route('/invoices', name: 'invoice_create', methods: ['POST'])]
    public function invoiceCreate(Request $request): JsonResponse
    {
        $id = $this->invoices->create($this->body($request), $this->actor->name());
        $this->audit('invoice.created', ['id' => $id]);

        return $this->json(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/invoices/{id}', name: 'invoice_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function invoiceUpdate(int $id, Request $request): JsonResponse
    {
        $this->invoices->update($id, $this->body($request));

        return $this->json(['id' => $id]);
    }

    #[Route('/invoices/{id}/status', name: 'invoice_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function invoiceStatus(int $id, Request $request): JsonResponse
    {
        $status = (string) ($this->body($request)['status'] ?? '');
        $this->invoices->setStatus($id, $status, $this->actor->name());
        $this->audit('invoice.status', ['id' => $id, 'status' => $status]);

        return $this->json(['id' => $id, 'status' => $status]);
    }

    #[Route('/invoices/{id}', name: 'invoice_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function invoiceDelete(int $id): JsonResponse
    {
        $this->invoices->delete($id);
        $this->audit('invoice.deleted', ['id' => $id]);

        return $this->json(['deleted' => true]);
    }

    /** @param array<string, mixed> $context */
    private function audit(string $event, array $context): void
    {
        $this->logger->info($event, $context + ['actor' => $this->actor->name()]);
    }
}
