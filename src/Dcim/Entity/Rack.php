<?php

declare(strict_types=1);

namespace App\Dcim\Entity;

use App\Organization\Entity\Branch;
use App\Organization\Entity\Location;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rack. Porta de `dcim.Rack` (netbox/dcim/models/racks.py).
 *
 * Regras de negócio e escrita: serviços em src/Dcim/Service (DBAL). Ver ADR-0016.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dcim_rack')]
#[ORM\UniqueConstraint(name: 'uniq_dcim_rack_name', columns: ['branch_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class Rack
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Branch $branch;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Location $location = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 16, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $facilityId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $serial = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $assetTag = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 42])]
    private int $uHeight = 42;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 19])]
    private int $width = 19;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $startingUnit = 1;

    #[ORM\Column(options: ['default' => false])]
    private bool $descUnits = false;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $outerDepthMm = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    public function __construct(Branch $branch, string $name)
    {
        $this->branch = $branch;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBranch(): Branch
    {
        return $this->branch;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function getFacilityId(): ?string
    {
        return $this->facilityId;
    }

    public function getSerial(): ?string
    {
        return $this->serial;
    }

    public function getAssetTag(): ?string
    {
        return $this->assetTag;
    }

    public function getUHeight(): int
    {
        return $this->uHeight;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getStartingUnit(): int
    {
        return $this->startingUnit;
    }

    public function isDescUnits(): bool
    {
        return $this->descUnits;
    }

    public function getOuterDepthMm(): ?int
    {
        return $this->outerDepthMm;
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
