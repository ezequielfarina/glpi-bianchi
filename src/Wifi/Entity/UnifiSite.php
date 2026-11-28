<?php

declare(strict_types=1);

namespace App\Wifi\Entity;

use App\Organization\Entity\Branch;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Site do controller UniFi (ex.: e6ovl2sz), opcionalmente ligado a uma filial.
 *
 * Regras e escrita: serviços em src/Wifi/Service (DBAL). Ver ADR-0020.
 */
#[ORM\Entity]
#[ORM\Table(name: 'unifi_site')]
#[ORM\UniqueConstraint(name: 'uniq_unifi_site_ref', columns: ['controller_id', 'site_ref'])]
#[ORM\HasLifecycleCallbacks]
class UnifiSite
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UnifiController::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UnifiController $controller;

    /** nome interno do site na API (ex.: "default", "e6ovl2sz") */
    #[ORM\Column(length: 64)]
    private string $siteRef;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Branch $branch = null;

    /** ainda existe no controller (última sincronização) */
    #[ORM\Column(options: ['default' => true])]
    private bool $present = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    public function __construct(UnifiController $controller, string $siteRef)
    {
        $this->controller = $controller;
        $this->siteRef = $siteRef;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getController(): UnifiController
    {
        return $this->controller;
    }

    public function getSiteRef(): string
    {
        return $this->siteRef;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }
}
