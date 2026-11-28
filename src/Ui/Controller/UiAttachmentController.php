<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Attachment\Service\AttachmentStorage;
use App\Identity\Security\CurrentActor;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Upload (multipart: file, owner_type, owner, kind, description), download e exclusão.
 * `owner` = UUID do ativo ou ID numérico de contrato/fornecedor/licença/filial/nota fiscal.
 */
#[AsController]
#[Route('/api/ui/attachments', name: 'ui_attachment_')]
final readonly class UiAttachmentController
{
    use UiJson;

    public function __construct(
        private CurrentActor $actor,
        private AttachmentStorage $storage,
        private Refs $refs,
        private Connection $db,
    ) {
    }

    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new ValidationException(['file' => 'Selecione um arquivo.']);
        }
        $ownerType = (string) $request->request->get('owner_type', '');
        $owner = (string) $request->request->get('owner', '');
        $ownerId = $this->resolveOwner($ownerType, $owner);

        $meta = $this->storage->store(
            $file,
            $ownerType,
            $ownerId,
            (string) $request->request->get('kind', 'document'),
            ($d = trim((string) $request->request->get('description', ''))) === '' ? null : mb_substr($d, 0, 500),
            $this->actor->name(),
        );
        if ('asset' === $ownerType) {
            $this->db->executeStatement(
                "INSERT INTO asset_history (asset_id, field, old_value, new_value, source, actor, created_at) VALUES (?, ?, NULL, ?, 'manual', ?, ?)",
                [$ownerId, 'photo' === $meta['kind'] ? 'photo.added' : 'attachment.added', $meta['filename'], $this->actor->name(), Refs::now()],
            );
        }

        return $this->json($meta, Response::HTTP_CREATED);
    }

    #[Route('/{uuid}', name: 'download', methods: ['GET'])]
    public function download(string $uuid, Request $request): Response
    {
        $file = $this->storage->locate($uuid) ?? throw new NotFoundException('Anexo não encontrado.');
        // decifra em fluxo (ADR-0019); sem Range: o conteúdo não está em claro no disco
        $storage = $this->storage;
        $response = new StreamedResponse(static function () use ($storage, $file): void {
            $storage->stream($file, static function (string $chunk): void {
                echo $chunk;
                flush();
            });
        });
        $response->headers->set('Content-Length', (string) $file['size']);
        $inline = str_starts_with($file['mime'], 'image/') || 'application/pdf' === $file['mime'];
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $inline && !$request->query->getBoolean('download') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file['filename'],
            (string) preg_replace('/[^\x20-\x7e]/', '_', $file['filename']),
        ));
        $response->headers->set('Content-Type', $file['mime']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // nada executa dentro do arquivo servido (PDF/SVG/HTML embutido)
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        // documento (NF, contrato) não fica no cache em disco do navegador; foto pode (miniaturas)
        $response->headers->set('Cache-Control', str_starts_with($file['mime'], 'image/') ? 'private, max-age=3600' : 'private, no-store');

        return $response;
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $row = $this->db->fetchAssociative('SELECT owner_type, owner_id, kind, filename FROM attachment WHERE uuid = ?', [$uuid]);
        if (false === $row || !$this->storage->delete($uuid)) {
            throw new NotFoundException('Anexo não encontrado.');
        }
        if ('asset' === $row['owner_type']) {
            $this->db->executeStatement(
                "INSERT INTO asset_history (asset_id, field, old_value, new_value, source, actor, created_at) VALUES (?, ?, ?, NULL, 'manual', ?, ?)",
                [$row['owner_id'], 'photo' === $row['kind'] ? 'photo.removed' : 'attachment.removed', $row['filename'], $this->actor->name(), Refs::now()],
            );
        }

        return $this->json(['deleted' => true]);
    }

    private function resolveOwner(string $type, string $owner): int
    {
        if ('asset' === $type) {
            return $this->refs->asset($owner)['id'] ?? throw new NotFoundException('Ativo não encontrado.');
        }
        $table = ['contract' => 'contract', 'supplier' => 'supplier', 'license' => 'license', 'branch' => 'branch', 'invoice' => 'invoice',
            // ADR-0016: fotos de locais, racks, equipamentos, tipos, circuitos e cabos
            'location' => 'location', 'rack' => 'dcim_rack', 'device' => 'dcim_device', 'device_type' => 'dcim_device_type',
            'circuit' => 'circuit', 'cable' => 'dcim_cable', 'cluster' => 'virt_cluster', 'vm' => 'virt_machine'][$type]
            ?? throw new ValidationException(['owner_type' => 'Tipo de dono inválido.']);
        if (!ctype_digit($owner) || !$this->refs->exists($table, (int) $owner)) {
            throw new NotFoundException('Registro não encontrado.');
        }

        return (int) $owner;
    }
}
