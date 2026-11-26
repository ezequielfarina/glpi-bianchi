<?php

declare(strict_types=1);

namespace App\Network\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organization\Entity\Organization;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * VLAN 802.1Q. Única por (organização, tag).
 */
#[ORM\Entity]
#[ORM\Table(name: 'vlan')]
#[ORM\UniqueConstraint(name: 'uniq_vlan_org_tag', columns: ['organization_id', 'tag'])]
#[ApiResource(operations: [new Get(), new GetCollection(), new Post()])]
class Vlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Organization $organization;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 4094)]
    private int $tag;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name;

    public function __construct(Organization $organization, int $tag, ?string $name = null)
    {
        $this->organization = $organization;
        $this->tag = $tag;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getTag(): int
    {
        return $this->tag;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }
}
