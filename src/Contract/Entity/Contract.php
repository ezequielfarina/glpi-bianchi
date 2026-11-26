<?php

declare(strict_types=1);

namespace App\Contract\Entity;

use App\Asset\Entity\Asset;
use App\Shared\Entity\TimestampableTrait;
use App\Supplier\Entity\Supplier;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Contrato com fornecedor: vigência, renovação, valor e periodicidade, ativos cobertos.
 * Status (vigente / vencendo / vencido) é calculado a partir de end_date e notice_days.
 */
#[ORM\Entity]
#[ORM\Table(name: 'contract')]
#[ORM\Index(name: 'idx_contract_end_date', columns: ['end_date'])]
#[ORM\HasLifecycleCallbacks]
class Contract
{
    use TimestampableTrait;

    public const array RENEWALS = ['none', 'manual', 'automatic'];
    public const array BILLING_PERIODS = ['one_time', 'monthly', 'quarterly', 'semiannual', 'yearly'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Supplier $supplier = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ContractType $contractType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(length: 16, options: ['default' => 'none'])]
    private string $renewal = 'none';

    /** Antecedência (dias) para alertar o vencimento / aviso prévio de cancelamento. */
    #[ORM\Column(nullable: true)]
    private ?int $noticeDays = null;

    #[ORM\Column(length: 16, options: ['default' => 'monthly'])]
    private string $billingPeriod = 'monthly';

    /** Valor por período de cobrança (BRL). */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    private ?string $value = null;

    /** @var Collection<int, Asset> */
    #[ORM\ManyToMany(targetEntity: Asset::class)]
    #[ORM\JoinTable(name: 'contract_asset')]
    private Collection $assets;

    public function __construct(string $title)
    {
        $this->title = $title;
        $this->assets = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getSupplier(): ?Supplier { return $this->supplier; }
    public function getContractType(): ?ContractType { return $this->contractType; }
    public function getNumber(): ?string { return $this->number; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function getRenewal(): string { return $this->renewal; }
    public function getNoticeDays(): ?int { return $this->noticeDays; }
    public function getBillingPeriod(): string { return $this->billingPeriod; }
    public function getValue(): ?string { return $this->value; }
    /** @return Collection<int, Asset> */
    public function getAssets(): Collection { return $this->assets; }
}
