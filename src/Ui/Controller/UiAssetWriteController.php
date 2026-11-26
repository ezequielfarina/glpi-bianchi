<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Asset\Service\InventoryNumberGenerator;
use App\Ui\Write\AssetWriter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/** Cadastro/edição manual de ativos (impressoras, monitores, coletores, tablets...). */
#[AsController]
#[Route('/api/ui/assets', name: 'ui_asset_')]
final readonly class UiAssetWriteController
{
    use UiJson;

    public function __construct(
        private AssetWriter $writer,
        private InventoryNumberGenerator $numbers,
    ) {
    }

    /** Prévia do próximo patrimônio (o número só é consumido ao salvar). */
    #[Route('/next-inventory-number', name: 'next_number', methods: ['GET'], priority: 10)]
    public function nextNumber(): JsonResponse
    {
        return $this->json(['next' => $this->numbers->preview()]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->json(['uuid' => $this->writer->create($this->body($request), self::ACTOR)], Response::HTTP_CREATED);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $this->writer->update($uuid, $this->body($request), self::ACTOR);

        return $this->json(['uuid' => $uuid]);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $this->writer->delete($uuid, self::ACTOR);

        return $this->json(['deleted' => true]);
    }
}
