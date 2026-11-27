<?php

declare(strict_types=1);

namespace App\Stock\Entity;

use App\Supplier\Entity\Supplier;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Nota fiscal de compra simplificada (ADR-0014): número, série, fornecedor, data, total informado
 * e itens. Situação: registrada → enviada para lançamento → lançada.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_number', columns: ['supplier_id', 'number', 'series'])]
#[ORM\Index(name: 'idx_invoice_issue_date', columns: ['issue_date'])]
class Invoice
{
    public const array STATUSES = ['registered', 'sent', 'posted'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Supplier $supplier;

    #[ORM\Column(length: 20)]
    private string $number;

    #[ORM\Column(length: 5, options: ['default' => ''])]
    private string $series = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $issueDate;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $total;

    #[ORM\Column(length: 16, options: ['default' => 'registered'])]
    private string $status = 'registered';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sentBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $postedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 255)]
    private string $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Supplier $supplier, string $number, \DateTimeImmutable $issueDate, string $total, string $createdBy)
    {
        $this->supplier = $supplier;
        $this->number = $number;
        $this->issueDate = $issueDate;
        $this->total = $total;
        $this->createdBy = $createdBy;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getSeries(): string
    {
        return $this->series;
    }

    public function getIssueDate(): \DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getSentBy(): ?string
    {
        return $this->sentBy;
    }

    public function getPostedAt(): ?\DateTimeImmutable
    {
        return $this->postedAt;
    }

    public function getPostedBy(): ?string
    {
        return $this->postedBy;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedBy(): string
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
