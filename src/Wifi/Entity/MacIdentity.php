<?php

declare(strict_types=1);

namespace App\Wifi\Entity;

use App\Asset\Entity\Asset;
use App\Identity\Entity\User;
use App\Organization\Entity\Branch;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Identidade de um endereço MAC: quem é a pessoa, qual o aparelho, até quando vale.
 * O controller UniFi só guarda o MAC; a identificação mora aqui (fonte da verdade).
 *
 * Regras e escrita: serviços em src/Wifi/Service (DBAL). Ver ADR-0020.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mac_identity')]
#[ORM\UniqueConstraint(name: 'uniq_mac_identity_mac', columns: ['mac'])]
#[ORM\Index(name: 'idx_mac_identity_status', columns: ['status', 'valid_until'])]
#[ORM\HasLifecycleCallbacks]
class MacIdentity
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** aa:bb:cc:dd:ee:ff (minúsculo, normalizado) */
    #[ORM\Column(length: 17)]
    private string $mac;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $personName = null;

    /** matrícula / documento interno */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $registration = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $department = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    /** pessoa do diretório/inventário, quando existe */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $person = null;

    #[ORM\Column(length: 20, options: ['default' => 'other'])]
    private string $deviceKind = 'other';

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $deviceBrand = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $deviceModel = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $hostname = null;

    /** ativo do inventário (patrimônio) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Asset $asset = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Branch $branch = null;

    /** acesso temporário (visitante): depois disso é revogado e retirado das redes */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    /** active | revoked */
    #[ORM\Column(length: 10, options: ['default' => 'active'])]
    private string $status = 'active';

    /** panel | imported (veio do controller sem identificação) */
    #[ORM\Column(length: 10)]
    private string $source = 'panel';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $createdBy = null;

    public function __construct(string $mac)
    {
        $this->mac = $mac;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMac(): string
    {
        return $this->mac;
    }

    public function getPersonName(): ?string
    {
        return $this->personName;
    }

    public function getRegistration(): ?string
    {
        return $this->registration;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getPerson(): ?User
    {
        return $this->person;
    }

    public function getDeviceKind(): string
    {
        return $this->deviceKind;
    }

    public function getDeviceBrand(): ?string
    {
        return $this->deviceBrand;
    }

    public function getDeviceModel(): ?string
    {
        return $this->deviceModel;
    }

    public function getHostname(): ?string
    {
        return $this->hostname;
    }

    public function getAsset(): ?Asset
    {
        return $this->asset;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }
}
