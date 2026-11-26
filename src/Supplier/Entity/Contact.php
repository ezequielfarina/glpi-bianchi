<?php

declare(strict_types=1);

namespace App\Supplier\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Contato (pessoa) de um fornecedor — comercial, suporte, financeiro... */
#[ORM\Entity]
#[ORM\Table(name: 'contact')]
#[ORM\HasLifecycleCallbacks]
class Contact
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'contacts')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Supplier $supplier;

    #[ORM\Column(length: 255)]
    private string $name;

    /** Cargo/papel: "Comercial", "Suporte N2", "Financeiro"... */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(length: 320, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mobile = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct(?Supplier $supplier, string $name)
    {
        $this->supplier = $supplier;
        $this->name = $name;
    }

    public function getId(): ?int { return $this->id; }
    public function getSupplier(): ?Supplier { return $this->supplier; }
    public function getName(): string { return $this->name; }
    public function getRole(): ?string { return $this->role; }
    public function getEmail(): ?string { return $this->email; }
    public function getPhone(): ?string { return $this->phone; }
    public function getMobile(): ?string { return $this->mobile; }
    public function getNotes(): ?string { return $this->notes; }
}
