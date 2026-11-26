<?php

declare(strict_types=1);

namespace App\Asset\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;

/**
 * Campo travado: o inventário automático NÃO sobrescreve.
 *
 * Equivalente ao `glpi_lockedfields`. Ex.: o técnico corrige o nome de um ativo
 * manualmente e trava `name` para que o próximo inventário não desfaça.
 *   asset = NULL → trava global (vale para todos os ativos)
 */
#[ORM\Entity]
#[ORM\Table(name: 'locked_field')]
#[ORM\UniqueConstraint(name: 'uniq_locked_field', columns: ['asset_id', 'field'])]
#[ApiResource(operations: [new Get(), new GetCollection(), new Post(), new Delete()])]
class LockedField
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Asset $asset;

    /** Nome do campo no domínio: "name", "serialNumber", "location", "os.version"... */
    #[ORM\Column(length: 128)]
    private string $field;

    /** Valor que o inventário tentou aplicar e foi bloqueado (diagnóstico). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastRejectedValue = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $field, ?Asset $asset = null)
    {
        $this->field = $field;
        $this->asset = $asset;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAsset(): ?Asset
    {
        return $this->asset;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function isGlobal(): bool
    {
        return null === $this->asset;
    }

    public function getLastRejectedValue(): ?string
    {
        return $this->lastRejectedValue;
    }

    public function setLastRejectedValue(?string $value): void
    {
        $this->lastRejectedValue = $value;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
