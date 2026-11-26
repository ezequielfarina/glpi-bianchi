<?php

declare(strict_types=1);

namespace App\License\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Tipo de licença (cadastro editável): OEM, Retail, Volume MAK/KMS, Assinatura M365...
 * `assignment` é o modelo padrão sugerido ao criar uma licença desse tipo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'license_type')]
#[ORM\UniqueConstraint(name: 'uniq_license_type_name', columns: ['name'])]
class LicenseType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(length: 16, options: ['default' => 'device'])]
    private string $assignment = License::ASSIGN_DEVICE;

    public function __construct(string $name, string $assignment = License::ASSIGN_DEVICE)
    {
        $this->name = $name;
        $this->assignment = $assignment;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getAssignment(): string { return $this->assignment; }
}
