<?php

declare(strict_types=1);

namespace App\Organization\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Unidade física da empresa: matriz ou filial (ADR-0010).
 *
 * - `kind`: `headquarters` (no máximo uma, garantido em BranchWriter) ou `branch`.
 * - Endereço estruturado (CEP/UF) + coordenadas para o mapa (WGS84, 6 casas ≈ 0,1 m).
 * - `networks`: lista de CIDRs (ex.: ["192.168.10.0/24"]) — ativos vindos do agente
 *   sem filial recebem a filial cuja rede contém o IP (prefixo mais específico vence).
 */
#[ORM\Entity]
#[ORM\Table(name: 'branch')]
#[ORM\UniqueConstraint(name: 'uniq_branch_name', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'uniq_branch_code', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
class Branch
{
    use TimestampableTrait;

    public const string KIND_HEADQUARTERS = 'headquarters';
    public const string KIND_BRANCH = 'branch';
    public const array KINDS = [self::KIND_HEADQUARTERS, self::KIND_BRANCH];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    /** Sigla curta para listas/etiquetas (ex.: MTZ, FIL-SP). */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(length: 16, options: ['default' => self::KIND_BRANCH])]
    private string $kind = self::KIND_BRANCH;

    /** CNPJ somente dígitos. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taxId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 320, nullable: true)]
    private ?string $email = null;

    /** CEP somente dígitos. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $zipCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $district = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $city = null;

    /** UF (2 letras). */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 6, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 6, nullable: true)]
    private ?string $longitude = null;

    /** @var list<string> CIDRs normalizados */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $networks = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(string $name, string $kind = self::KIND_BRANCH)
    {
        $this->name = $name;
        $this->kind = $kind;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCode(): ?string { return $this->code; }
    public function getKind(): string { return $this->kind; }
    public function isHeadquarters(): bool { return self::KIND_HEADQUARTERS === $this->kind; }
    public function getTaxId(): ?string { return $this->taxId; }
    public function getPhone(): ?string { return $this->phone; }
    public function getEmail(): ?string { return $this->email; }
    public function getZipCode(): ?string { return $this->zipCode; }
    public function getStreet(): ?string { return $this->street; }
    public function getNumber(): ?string { return $this->number; }
    public function getComplement(): ?string { return $this->complement; }
    public function getDistrict(): ?string { return $this->district; }
    public function getCity(): ?string { return $this->city; }
    public function getState(): ?string { return $this->state; }
    public function getLatitude(): ?string { return $this->latitude; }
    public function getLongitude(): ?string { return $this->longitude; }
    /** @return list<string> */
    public function getNetworks(): array { return $this->networks; }
    public function getNotes(): ?string { return $this->notes; }
    public function isActive(): bool { return $this->active; }
}
