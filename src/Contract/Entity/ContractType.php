<?php

declare(strict_types=1);

namespace App\Contract\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Tipo de contrato (cadastro editável: suporte, locação, link, licenciamento...). */
#[ORM\Entity]
#[ORM\Table(name: 'contract_type')]
#[ORM\UniqueConstraint(name: 'uniq_contract_type_name', columns: ['name'])]
class ContractType
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
