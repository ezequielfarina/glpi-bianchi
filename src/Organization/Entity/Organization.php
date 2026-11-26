<?php

declare(strict_types=1);

namespace App\Organization\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organization\Repository\OrganizationRepository;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Unidade organizacional (equivalente à "Entidade" do GLPI).
 *
 * Hierárquica: `path` (ltree) é mantido por trigger a partir de `parent_id`,
 * permitindo consultas de subárvore em O(log n) com índice GiST:
 *   WHERE o.path <@ '1.4'   -- toda a subárvore da organização 4
 */
#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[ORM\UniqueConstraint(name: 'uniq_organization_code', columns: ['code'])]
#[ORM\Index(name: 'idx_organization_parent', columns: ['parent_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(operations: [new Get(), new GetCollection(), new Post(), new Patch()])]
class Organization
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Código estável usado em regras e configuração (ex.: "root", "filial-sp"). */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9][a-z0-9_-]*$/')]
    private string $code;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Organization $parent = null;

    /** Somente leitura: calculado pelo trigger `trg_organization_path`. */
    #[ORM\Column(type: 'ltree', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    private ?string $path = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    public function __construct(string $code, string $name, ?self $parent = null)
    {
        $this->code = $code;
        $this->name = $name;
        $this->parent = $parent;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): void
    {
        $this->parent = $parent;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }
}
