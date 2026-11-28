<?php

declare(strict_types=1);

namespace App\Dcim\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sistema operacional / plataforma (Windows Server 2022, Debian 13, FortiGate-VM…). Porta de `dcim.Platform`.
 * Lista editável que alimenta o campo texto `virt_machine.platform` (ADR-0018).
 */
#[ORM\Entity]
#[ORM\Table(name: 'dcim_platform')]
#[ORM\UniqueConstraint(name: 'uniq_dcim_platform_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class Platform
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
