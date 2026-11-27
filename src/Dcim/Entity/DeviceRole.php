<?php

declare(strict_types=1);

namespace App\Dcim\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Função do equipamento (Switch, Patch panel, Ponto de rede…). Porta de `dcim.DeviceRole`.
 *
 * Regras de negócio e escrita: serviços em src/Dcim/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dcim_device_role')]
#[ORM\UniqueConstraint(name: 'uniq_dcim_device_role_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class DeviceRole
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 6, options: ['default' => '9e9e9e'])]
    private string $color = '9e9e9e';

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

    public function getColor(): string
    {
        return $this->color;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
