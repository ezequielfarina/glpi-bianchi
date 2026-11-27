<?php

declare(strict_types=1);

namespace App\Attachment\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Arquivo anexado (foto ou documento) a um ativo, contrato, fornecedor ou licença.
 *
 * Polimórfico por (owner_type, owner_id) — sem FK, para servir a qualquer módulo.
 * O binário fica em disco (ATTACHMENTS_DIR, padrão /var/lib/glpi-bianchi/files);
 * aqui só metadados + sha256 para integridade/deduplicação. Ver ADR-0009.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attachment')]
#[ORM\UniqueConstraint(name: 'uniq_attachment_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_attachment_owner', columns: ['owner_type', 'owner_id'])]
class Attachment
{
    public const array OWNER_TYPES = ['asset', 'contract', 'supplier', 'license', 'branch', 'invoice'];
    public const string KIND_PHOTO = 'photo';
    public const string KIND_DOCUMENT = 'document';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    #[ORM\Column(length: 32)]
    private string $ownerType;

    #[ORM\Column]
    private int $ownerId;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 127)]
    private string $mimeType;

    #[ORM\Column(type: Types::BIGINT)]
    private string $sizeBytes;

    #[ORM\Column(length: 64)]
    private string $sha256;

    /** Caminho relativo a ATTACHMENTS_DIR (ex.: "2026/11/<uuid>"). */
    #[ORM\Column(length: 255)]
    private string $storagePath;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $ownerType, int $ownerId, string $kind, string $filename, string $mimeType, int $sizeBytes, string $sha256, string $storagePath)
    {
        $this->uuid = Uuid::v7();
        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;
        $this->kind = $kind;
        $this->filename = $filename;
        $this->mimeType = $mimeType;
        $this->sizeBytes = (string) $sizeBytes;
        $this->sha256 = $sha256;
        $this->storagePath = $storagePath;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): Uuid { return $this->uuid; }
    public function getOwnerType(): string { return $this->ownerType; }
    public function getOwnerId(): int { return $this->ownerId; }
    public function getKind(): string { return $this->kind; }
    public function getFilename(): string { return $this->filename; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSizeBytes(): int { return (int) $this->sizeBytes; }
    public function getSha256(): string { return $this->sha256; }
    public function getStoragePath(): string { return $this->storagePath; }
    public function getDescription(): ?string { return $this->description; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
