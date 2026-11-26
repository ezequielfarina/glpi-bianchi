<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Asset\Entity\Asset;
use App\Inventory\Enum\RawInventoryStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * Payload bruto recebido do agente, já em JSON (XML legado é convertido).
 *
 * Por que guardar: auditoria ("o que o agente mandou de fato?"), reprocessamento
 * após correção de bug no importador, e diagnóstico sem precisar do agente.
 * Retenção: job de limpeza mantém os N últimos por device (fase 2). Ver ADR-0005.
 */
#[ORM\Entity]
#[ORM\Table(name: 'raw_inventory')]
#[ORM\Index(name: 'idx_raw_inventory_device_received', columns: ['device_id', 'received_at'])]
#[ORM\Index(name: 'idx_raw_inventory_status', columns: ['status'])]
#[ApiResource(operations: [new Get(), new GetCollection()], order: ['receivedAt' => 'DESC'])]
#[ApiFilter(SearchFilter::class, properties: ['deviceId' => 'exact', 'status' => 'exact'])]
class RawInventory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Agent $agent;

    #[ORM\Column(length: 255)]
    private string $deviceId;

    /** itemtype do inventário: Computer, NetworkEquipment, Printer... */
    #[ORM\Column(length: 64)]
    private string $itemtype;

    /** Formato original enviado: json | xml */
    #[ORM\Column(length: 8)]
    private string $sourceFormat;

    /** Inventário parcial (só seções alteradas) — o agente envia após o 1º completo. */
    #[ORM\Column(options: ['default' => false])]
    private bool $partial;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    #[Ignore]
    private array $payload;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column(length: 16, enumType: RawInventoryStatus::class)]
    private RawInventoryStatus $status = RawInventoryStatus::Received;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Asset $asset = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /** Duração do processamento em ms (métrica para o painel do Grafana). */
    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        ?Agent $agent,
        string $deviceId,
        string $itemtype,
        string $sourceFormat,
        bool $partial,
        array $payload,
        int $sizeBytes,
    ) {
        $this->agent = $agent;
        $this->deviceId = $deviceId;
        $this->itemtype = $itemtype;
        $this->sourceFormat = $sourceFormat;
        $this->partial = $partial;
        $this->payload = $payload;
        $this->sizeBytes = $sizeBytes;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function markProcessing(): void
    {
        $this->status = RawInventoryStatus::Processing;
        $this->error = null;
    }

    public function markProcessed(Asset $asset, int $durationMs): void
    {
        $this->status = RawInventoryStatus::Processed;
        $this->asset = $asset;
        $this->processedAt = new \DateTimeImmutable();
        $this->durationMs = $durationMs;
    }

    public function markFailed(string $error): void
    {
        $this->status = RawInventoryStatus::Failed;
        $this->error = mb_substr($error, 0, 10000);
        $this->processedAt = new \DateTimeImmutable();
    }

    public function markRejected(string $reason): void
    {
        $this->status = RawInventoryStatus::Rejected;
        $this->error = mb_substr($reason, 0, 10000);
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getAgent(): ?Agent
    {
        return $this->agent;
    }

    public function getDeviceId(): string
    {
        return $this->deviceId;
    }

    public function getItemtype(): string
    {
        return $this->itemtype;
    }

    public function getSourceFormat(): string
    {
        return $this->sourceFormat;
    }

    public function isPartial(): bool
    {
        return $this->partial;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getStatus(): RawInventoryStatus
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getAsset(): ?Asset
    {
        return $this->asset;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }
}
