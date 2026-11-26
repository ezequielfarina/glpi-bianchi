<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Organization\Entity\Organization;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pessoa a quem ativos são atribuídos (usuário final / responsável técnico).
 *
 * No MVP é cadastro de referência — não faz login. Fase 2: login via LDAP/OIDC,
 * com `authSource` indicando a origem e `externalId` o objectGUID/sub.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_username', columns: ['username'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(operations: [new Get(), new GetCollection()])]
class User
{
    use TimestampableTrait;

    public const string AUTH_LOCAL = 'local';
    public const string AUTH_LDAP = 'ldap';
    public const string AUTH_OIDC = 'oidc';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** sAMAccountName / login, sempre normalizado em minúsculas (unicidade case-insensitive). */
    #[ORM\Column(length: 255)]
    private string $username;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(length: 320, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 16, options: ['default' => self::AUTH_LOCAL])]
    private string $authSource = self::AUTH_LOCAL;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Organization $organization = null;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class, inversedBy: 'members')]
    #[ORM\JoinTable(name: 'user_group')]
    private Collection $groups;

    public function __construct(string $username)
    {
        $this->username = mb_strtolower(trim($username));
        $this->groups = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getAuthSource(): string
    {
        return $this->authSource;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    /** @return Collection<int, Group> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }
}
