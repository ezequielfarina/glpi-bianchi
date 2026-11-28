<?php

declare(strict_types=1);

namespace App\Virtualization\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Grupo de clusters. Porta de `virtualization.ClusterGroup`.
 *
 * Regras de negócio e escrita: serviços em src/Virtualization/Service (DBAL). Ver ADR-0017.
 */
#[ORM\Entity]
#[ORM\Table(name: 'virt_cluster_group')]
#[ORM\UniqueConstraint(name: 'uniq_virt_cluster_group_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class ClusterGroup
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
