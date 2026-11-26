<?php

declare(strict_types=1);

namespace App\License\Entity;

use App\Contract\Entity\Contract;
use App\Shared\Entity\TimestampableTrait;
use App\Supplier\Entity\Supplier;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Licença de software adquirida.
 *
 * Modelos de atribuição:
 *   device → cada assento é consumido por um ativo (Windows OEM, Office por PC)
 *   user   → cada assento é consumido por uma pessoa (Microsoft 365, CAL usuário)
 *   volume → uma chave com N ativações consumidas por ativos (MAK/KMS)
 *
 * `licenseKey` é gravada CRIPTOGRAFADA (libsodium secretbox, chave em DATA_ENCRYPTION_KEY).
 * `softwareMatch` (ILIKE) cruza com o software detectado pelo agente para conformidade.
 */
#[ORM\Entity]
#[ORM\Table(name: 'license')]
#[ORM\Index(name: 'idx_license_expires_at', columns: ['expires_at'])]
#[ORM\HasLifecycleCallbacks]
class License
{
    use TimestampableTrait;

    public const string ASSIGN_DEVICE = 'device';
    public const string ASSIGN_USER = 'user';
    public const string ASSIGN_VOLUME = 'volume';
    public const array ASSIGNMENTS = [self::ASSIGN_DEVICE, self::ASSIGN_USER, self::ASSIGN_VOLUME];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private LicenseType $licenseType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?LicenseCategory $licenseCategory = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Supplier $supplier = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Contract $contract = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $assignment;

    #[ORM\Column(options: ['default' => 1])]
    private int $seats = 1;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $unitPrice = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $purchaseDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /** Criptografada (base64 de nonce||ciphertext). Nunca exposta em listagens. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $licenseKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $softwareMatch = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct(LicenseType $licenseType, string $name, string $assignment)
    {
        $this->licenseType = $licenseType;
        $this->name = $name;
        $this->assignment = $assignment;
    }

    public function getId(): ?int { return $this->id; }
    public function getLicenseType(): LicenseType { return $this->licenseType; }
    public function getLicenseCategory(): ?LicenseCategory { return $this->licenseCategory; }
    public function getSupplier(): ?Supplier { return $this->supplier; }
    public function getContract(): ?Contract { return $this->contract; }
    public function getName(): string { return $this->name; }
    public function getAssignment(): string { return $this->assignment; }
    public function getSeats(): int { return $this->seats; }
    public function getUnitPrice(): ?string { return $this->unitPrice; }
    public function getPurchaseDate(): ?\DateTimeImmutable { return $this->purchaseDate; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getSoftwareMatch(): ?string { return $this->softwareMatch; }
    public function getNotes(): ?string { return $this->notes; }
}
