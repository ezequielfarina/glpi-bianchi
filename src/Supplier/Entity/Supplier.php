<?php

declare(strict_types=1);

namespace App\Supplier\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Fornecedor / prestador (CNPJ único quando informado). */
#[ORM\Entity]
#[ORM\Table(name: 'supplier')]
#[ORM\UniqueConstraint(name: 'uniq_supplier_tax_id', columns: ['tax_id'])]
#[ORM\Index(name: 'idx_supplier_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class Supplier
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tradeName = null;

    /** CNPJ/CPF somente dígitos. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taxId = null;

    #[ORM\Column(length: 320, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** @var Collection<int, Contact> */
    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'supplier')]
    private Collection $contacts;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->contacts = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getTradeName(): ?string { return $this->tradeName; }
    public function getTaxId(): ?string { return $this->taxId; }
    public function getEmail(): ?string { return $this->email; }
    public function getPhone(): ?string { return $this->phone; }
    public function getWebsite(): ?string { return $this->website; }
    public function getAddress(): ?string { return $this->address; }
    public function getNotes(): ?string { return $this->notes; }
    public function isActive(): bool { return $this->active; }
    /** @return Collection<int, Contact> */
    public function getContacts(): Collection { return $this->contacts; }
}
