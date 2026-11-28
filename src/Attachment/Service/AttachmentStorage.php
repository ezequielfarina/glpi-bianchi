<?php

declare(strict_types=1);

namespace App\Attachment\Service;

use App\Attachment\Entity\Attachment;
use App\Shared\Security\FileCipher;
use App\Shared\Security\SecretBox;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Grava anexos em disco (ATTACHMENTS_DIR) + metadados na tabela `attachment`.
 *
 * Segurança:
 *  - tipo detectado pelo CONTEÚDO (finfo), não pela extensão enviada;
 *  - lista branca de MIME; fotos só imagem; limite de tamanho;
 *  - arquivo salvo com nome = UUID, fora do webroot; download sempre via controller
 *    autenticado, com Content-Disposition e nosniff.
 */
final readonly class AttachmentStorage
{
    public const int MAX_BYTES = 20 * 1024 * 1024;

    /** MIME => extensão sugerida no download. */
    public const array ALLOWED = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/zip' => 'zip',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/msword' => 'doc', 'application/vnd.ms-excel' => 'xls',
        'application/vnd.oasis.opendocument.text' => 'odt', 'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/xml' => 'xml', 'text/xml' => 'xml',
    ];

    public function __construct(
        private Connection $db,
        private string $attachmentsDir,
        private SecretBox $box,
    ) {
    }

    /** @return array<string, mixed> metadados do anexo criado */
    public function store(UploadedFile $file, string $ownerType, int $ownerId, string $kind, ?string $description, ?string $actor): array
    {
        if (!$file->isValid()) {
            throw new ValidationException(['file' => 'Falha no upload: '.$file->getErrorMessage()]);
        }
        if (!\in_array($ownerType, Attachment::OWNER_TYPES, true)) {
            throw new ValidationException(['ownerType' => 'Tipo de dono inválido.']);
        }
        if (!\in_array($kind, [Attachment::KIND_PHOTO, Attachment::KIND_DOCUMENT], true)) {
            throw new ValidationException(['kind' => 'Use "photo" ou "document".']);
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new ValidationException(['file' => \sprintf('Arquivo vazio ou maior que %d MB.', self::MAX_BYTES / 1024 / 1024)]);
        }

        $mime = (string) (new \finfo(\FILEINFO_MIME_TYPE))->file($file->getPathname());
        // .docx/.xlsx são ZIP: finfo às vezes devolve application/zip ou octet-stream
        if (\in_array($mime, ['application/zip', 'application/octet-stream'], true)) {
            $byExt = array_search(strtolower($file->getClientOriginalExtension()), self::ALLOWED, true);
            if (\is_string($byExt) && str_starts_with($byExt, 'application/vnd.')) {
                $mime = $byExt;
            }
        }
        if (!isset(self::ALLOWED[$mime])) {
            throw new ValidationException(['file' => \sprintf('Tipo de arquivo não permitido (%s).', $mime)]);
        }
        if (Attachment::KIND_PHOTO === $kind && !str_starts_with($mime, 'image/')) {
            throw new ValidationException(['file' => 'A foto precisa ser uma imagem (JPG, PNG, WebP).']);
        }

        $uuid = Uuid::v7();
        $relative = date('Y/m').'/'.$uuid->toRfc4122();
        $target = rtrim($this->attachmentsDir, '/').'/'.$relative;
        if (!is_dir(\dirname($target)) && !mkdir(\dirname($target), 0o750, true) && !is_dir(\dirname($target))) {
            throw new \RuntimeException('Não foi possível criar o diretório de anexos: '.\dirname($target));
        }
        $sha256 = (string) hash_file('sha256', $file->getPathname());
        // cifrado em repouso (ADR-0019): o texto claro nunca é gravado no diretório de anexos
        $fileKey = FileCipher::newKey();
        FileCipher::encryptFile($file->getPathname(), $target, $fileKey);
        @unlink($file->getPathname());

        $filename = $this->safeFilename($file->getClientOriginalName(), self::ALLOWED[$mime]);
        $this->db->insert('attachment', [
            'uuid' => $uuid->toRfc4122(),
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'kind' => $kind,
            'filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'storage_path' => $relative,
            'encryption_key' => $this->wrapKey($fileKey, $uuid->toRfc4122()),
            'description' => $description,
            'created_by' => $actor,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'),
        ]);

        return ['uuid' => $uuid->toRfc4122(), 'filename' => $filename, 'mime_type' => $mime, 'size_bytes' => $size, 'kind' => $kind];
    }

    /** @return array{path: string, filename: string, mime: string, size: int, key: string|null}|null */
    public function locate(string $uuid): ?array
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT filename, mime_type, size_bytes, storage_path, encryption_key FROM attachment WHERE uuid = ?', [$uuid]);
        if (false === $row) {
            return null;
        }
        $path = rtrim($this->attachmentsDir, '/').'/'.$row['storage_path'];

        return is_file($path) ? [
            'path' => $path, 'filename' => (string) $row['filename'], 'mime' => (string) $row['mime_type'], 'size' => (int) $row['size_bytes'],
            'key' => null === $row['encryption_key'] ? null : $this->unwrapKey((string) $row['encryption_key'], strtolower($uuid)),
        ] : null;
    }

    /**
     * Conteúdo em claro, em blocos (download). Arquivo legado ainda não cifrado é lido direto.
     *
     * @param array{path: string, key: string|null} $file
     * @param callable(string): void                $sink
     */
    public function stream(array $file, callable $sink): void
    {
        if (null !== $file['key']) {
            FileCipher::decryptTo($file['path'], $file['key'], $sink);

            return;
        }
        $h = fopen($file['path'], 'r') ?: throw new \RuntimeException('Anexo ilegível.');
        try {
            while (!feof($h)) {
                $sink((string) fread($h, 65536));
            }
        } finally {
            fclose($h);
        }
    }

    /**
     * Cifra anexos gravados antes da ADR-0019 (encryption_key nulo). Idempotente.
     *
     * @return array{encrypted: int, missing: int}
     */
    public function encryptLegacy(?callable $progress = null): array
    {
        $done = 0;
        $missing = 0;
        foreach ($this->db->fetchAllAssociative('SELECT uuid, storage_path FROM attachment WHERE encryption_key IS NULL ORDER BY id') as $row) {
            $path = rtrim($this->attachmentsDir, '/').'/'.$row['storage_path'];
            if (!is_file($path)) {
                ++$missing;
                continue;
            }
            if (FileCipher::isEncrypted($path)) {
                continue; // gravado cifrado mas sem chave no banco: não mexe (precisa de análise manual)
            }
            $key = FileCipher::newKey();
            FileCipher::encryptFile($path, $path, $key);
            $this->db->executeStatement('UPDATE attachment SET encryption_key = ? WHERE uuid = ?', [$this->wrapKey($key, (string) $row['uuid']), $row['uuid']]);
            ++$done;
            if (null !== $progress) {
                $progress($done);
            }
        }

        return ['encrypted' => $done, 'missing' => $missing];
    }

    /** Regrava as chaves de arquivo com a chave mestra atual (rotação). @return int quantas */
    public function rewrapKeys(): int
    {
        $n = 0;
        foreach ($this->db->fetchAllAssociative('SELECT uuid, encryption_key FROM attachment WHERE encryption_key IS NOT NULL') as $row) {
            if (!$this->box->needsRotation((string) $row['encryption_key'])) {
                continue;
            }
            $uuid = (string) $row['uuid'];
            $this->db->executeStatement('UPDATE attachment SET encryption_key = ? WHERE uuid = ?', [$this->wrapKey($this->unwrapKey((string) $row['encryption_key'], $uuid), $uuid), $uuid]);
            ++$n;
        }

        return $n;
    }

    private function wrapKey(#[\SensitiveParameter] string $key, string $uuid): string
    {
        return $this->box->encrypt($key, 'attachment.key:'.strtolower($uuid));
    }

    private function unwrapKey(string $wrapped, string $uuid): string
    {
        return $this->box->decrypt($wrapped, 'attachment.key:'.strtolower($uuid));
    }

    public function delete(string $uuid): bool
    {
        $found = $this->locate($uuid);
        $deleted = $this->db->executeStatement('DELETE FROM attachment WHERE uuid = ?', [$uuid]) > 0;
        if ($deleted && null !== $found) {
            @unlink($found['path']);
        }

        return $deleted;
    }

    /** Remove todos os anexos de um dono (ex.: ao excluir fornecedor/contrato/licença). */
    public function deleteAllOf(string $ownerType, int $ownerId): void
    {
        foreach ($this->db->fetchFirstColumn('SELECT uuid FROM attachment WHERE owner_type = ? AND owner_id = ?', [$ownerType, $ownerId]) as $uuid) {
            $this->delete((string) $uuid);
        }
    }

    private function safeFilename(string $original, string $ext): string
    {
        $base = pathinfo($original, \PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^\pL\pN._ -]+/u', '_', $base);
        $base = trim(mb_substr($base, 0, 120), ' ._') ?: 'arquivo';

        return $base.'.'.$ext;
    }
}
