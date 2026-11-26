<?php

declare(strict_types=1);

namespace App\Tools\Entity;

use App\Asset\Entity\Asset;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dispositivo encontrado num scan. `state`: `up` (respondeu ao nmap) ou `arp` (não respondeu,
 * mas está na tabela ARP do gateway via SNMP — típico de Windows com firewall bloqueando ping).
 * `macSource`: arp (mesmo segmento) | nbstat (NetBIOS) | snmp (ARP do gateway) | inventory.
 * `assetId`/`matchBy`: correlação com o inventário (mac | ip | name).
 */
#[ORM\Entity]
#[ORM\Table(name: 'network_scan_host')]
#[ORM\UniqueConstraint(name: 'uniq_network_scan_host_ip', columns: ['scan_id', 'ip'])]
#[ORM\Index(name: 'idx_network_scan_host_mac', columns: ['mac'])]
class NetworkScanHost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NetworkScan $scan;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(length: 17, nullable: true)]
    private ?string $mac = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $macSource = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vendor = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hostname = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $netbiosName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $domain = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $os = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $osAccuracy = null;

    #[ORM\Column(length: 32, options: ['default' => 'unknown'])]
    private string $deviceType = 'unknown';

    #[ORM\Column(length: 16, options: ['default' => 'up'])]
    private string $state = 'up';

    #[ORM\Column(nullable: true)]
    private ?int $latencyMs = null;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $ports = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $details = [];

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Asset $asset = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $matchBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(NetworkScan $scan, string $ip)
    {
        $this->scan = $scan;
        $this->ip = $ip;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }
    public function getIp(): string { return $this->ip; }
    public function getScan(): NetworkScan { return $this->scan; }
}
