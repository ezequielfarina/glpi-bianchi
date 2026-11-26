<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Organization\Entity\Organization;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Grupo responsável por ativos (ex.: "Infra", "Redes", "Financeiro").
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_group')]
#[ORM\UniqueConstraint(name: 'uniq_group_org_name', columns: ['organization_id', 'name'])]
#[ApiResource(operations: [new Get(), new GetCollection()])]
class Group
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Organization $organization;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'groups')]
    private Collection $members;

    public function __construct(Organization $organization, string $name)
    {
        $this->organization = $organization;
        $this->name = $name;
        $this->members = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    /** @return Collection<int, User> */
    public function getMembers(): Collection
    {
        return $this->members;
    }
}
