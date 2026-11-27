<?php

declare(strict_types=1);

namespace App\Dcim\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cabo. Porta de `dcim.Cable` (netbox/dcim/models/cables.py).
 *
 * Regras de negócio e escrita: serviços em src/Dcim/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dcim_cable')]
#[ORM\Index(name: 'idx_dcim_cable_label', columns: ['label'])]
#[ORM\HasLifecycleCallbacks]
class Cable
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 16, options: ['default' => 'connected'])]
    private string $status = 'connected';

    #[ORM\Column(length: 6, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $length = null;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $lengthUnit = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    public function __construct()
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getLength(): ?string
    {
        return $this->length;
    }

    public function getLengthUnit(): ?string
    {
        return $this->lengthUnit;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getComments(): ?string
    {
        return $this->comments;
    }
}
