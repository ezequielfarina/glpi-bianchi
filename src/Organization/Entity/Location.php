<?php

declare(strict_types=1);

namespace App\Organization\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Localização física hierárquica: Site > Prédio > Andar > Sala > Rack.
 * `path` (ltree) mantido por trigger, igual a Organization.
 */
#[ORM\Entity]
#[ORM\Table(name: 'location')]
#[ORM\UniqueConstraint(name: 'uniq_location_org_parent_name', columns: ['organization_id', 'parent_id', 'name'])]
#[ORM\Index(name: 'idx_location_parent', columns: ['parent_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(operations: [new Get(), new GetCollection(), new Post(), new Patch()])]
class Location
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Location $parent = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'ltree', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    private ?string $path = null;

    public function __construct(Organization $organization, string $name, ?self $parent = null)
    {
        $this->organization = $organization;
        $this->name = $name;
        $this->parent = $parent;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): void
    {
        $this->parent = $parent;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = $code;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): void
    {
        $this->address = $address;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }
}
