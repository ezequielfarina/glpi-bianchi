<?php

declare(strict_types=1);

namespace App\License\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Categoria de licença (cadastro editável): Sistema operacional, Office, Antivírus... */
#[ORM\Entity]
#[ORM\Table(name: 'license_category')]
#[ORM\UniqueConstraint(name: 'uniq_license_category_name', columns: ['name'])]
class LicenseCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
}
