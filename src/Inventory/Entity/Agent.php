<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Asset\Entity\Asset;
use App\Organization\Entity\Organization;
use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instância do GLPI Agent que contatou o servidor. Identificada pelo `deviceid`
 * gerado pelo próprio agente (ex.: "srv-web01-2026-11-26-10-00-00").
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent')]
#[ORM\UniqueConstraint(name: 'uniq_agent_device_id', columns: ['device_id'])]
#[ORM\Index(name: 'idx_agent_last_contact', columns: ['last_contact_at'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(operations: [new Get(), new GetCollection()], order: ['lastContactAt' => 'DESC'])]
#[ApiFilter(SearchFilter::class, properties: ['deviceId' => 'exact', 'name' => 'ipartial'])]
class Agent
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $deviceId;

    #[ORM\Column(length: 255)]
    private string $name;

    /** "GLPI-Agent_v1.11" */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $version = null;

    /** Valor do header "GLPI-Agent-ID" (UUID do agente, protocolo JSON). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $agentUuid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tag = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $remoteIp = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgent = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $enabledTasks = [];

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Asset $asset = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Organization $organization = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastContactAt;

    public function __construct(string $deviceId)
    {
        $this->deviceId = $deviceId;
        $this->name = self::nameFromDeviceId($deviceId);
        $this->lastContactAt = new \DateTimeImmutable();
    }

    /** "srv-web01-2026-11-26-10-00-00" → "srv-web01" (mesmo critério do GLPI). */
    public static function nameFromDeviceId(string $deviceId): string
    {
        return (string) preg_replace('/-\d{4}(-\d{2}){5}$/', '', $deviceId);
    }

    public function touch(?string $remoteIp, ?string $userAgent, ?string $agentUuid): void
    {
        $this->lastContactAt = new \DateTimeImmutable();
        $this->remoteIp = $remoteIp;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 512);
        $this->agentUuid = $agentUuid ?? $this->agentUuid;
        if (null !== $userAgent && preg_match('#(GLPI-Agent|FusionInventory-Agent)[_/ ]v?([\w.\-]+)#i', $userAgent, $m)) {
            $this->version = $m[1].'_v'.$m[2];
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeviceId(): string
    {
        return $this->deviceId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): void
    {
        $this->version = $version;
    }

    public function getAgentUuid(): ?string
    {
        return $this->agentUuid;
    }

    public function getTag(): ?string
    {
        return $this->tag;
    }

    public function setTag(?string $tag): void
    {
        $this->tag = $tag;
    }

    public function getRemoteIp(): ?string
    {
        return $this->remoteIp;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /** @return list<string> */
    public function getEnabledTasks(): array
    {
        return $this->enabledTasks;
    }

    /** @param list<string> $enabledTasks */
    public function setEnabledTasks(array $enabledTasks): void
    {
        $this->enabledTasks = array_values(array_unique($enabledTasks));
    }

    public function getAsset(): ?Asset
    {
        return $this->asset;
    }

    public function setAsset(?Asset $asset): void
    {
        $this->asset = $asset;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function getLastContactAt(): \DateTimeImmutable
    {
        return $this->lastContactAt;
    }
}
