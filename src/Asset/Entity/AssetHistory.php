<?php

declare(strict_types=1);

namespace App\Asset\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Asset\Enum\ChangeSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trilha de auditoria por campo (equivalente ao `glpi_logs`). Append-only.
 *
 * Tabela que mais cresce no sistema; candidata a particionamento por
 * `created_at` (RANGE mensal) quando passar de ~50M linhas. Ver docs/database.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'asset_history')]
#[ORM\Index(name: 'idx_asset_history_asset_created', columns: ['asset_id', 'created_at'])]
#[ApiResource(operations: [new Get(), new GetCollection()], order: ['createdAt' => 'DESC'])]
class AssetHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    /** Campo alterado, ou evento: "component.added", "software.removed"... */
    #[ORM\Column(length: 128)]
    private string $field;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oldValue;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $newValue;

    #[ORM\Column(length: 16, enumType: ChangeSource::class)]
    private ChangeSource $source;

    /** Quem alterou: usuário, "agent:<deviceid>", "api"... */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $actor;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Asset $asset,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        ChangeSource $source,
        ?string $actor = null,
    ) {
        $this->asset = $asset;
        $this->field = $field;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->source = $source;
        $this->actor = $actor;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function getSource(): ChangeSource
    {
        return $this->source;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
