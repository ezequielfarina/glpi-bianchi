<?php

declare(strict_types=1);

namespace App\Network\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Asset\Entity\Asset;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Interface de rede de um ativo (NIC de computador ou porta de switch).
 *
 * MAC normalizado em minúsculas com ':' (aa:bb:cc:dd:ee:ff). Busca por MAC é a
 * principal consulta de troubleshooting ("de quem é este MAC na tabela ARP?").
 */
#[ORM\Entity]
#[ORM\Table(name: 'network_port')]
#[ORM\Index(name: 'idx_network_port_mac', columns: ['mac_address'])]
#[ORM\Index(name: 'idx_network_port_asset', columns: ['asset_id'])]
#[ApiResource(operations: [new Get(), new GetCollection()])]
#[ApiFilter(SearchFilter::class, properties: ['macAddress' => 'exact', 'asset' => 'exact'])]
class NetworkPort
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'networkPorts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 17, nullable: true)]
    private ?string $macAddress = null;

    /** ifIndex SNMP (switches) — chave de reconciliação em equipamentos de rede. */
    #[ORM\Column(nullable: true)]
    private ?int $ifIndex = null;

    /** ethernet, wifi, loopback, bridge, aggregate... */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(nullable: true)]
    private ?int $speedMbps = null;

    /** up | down */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $operStatus = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $virtual = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $dynamic = false;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $attributes = [];

    /** @var Collection<int, IpAddress> */
    #[ORM\OneToMany(targetEntity: IpAddress::class, mappedBy: 'networkPort', cascade: ['persist'], orphanRemoval: true)]
    private Collection $ipAddresses;

    /** @var Collection<int, Vlan> */
    #[ORM\ManyToMany(targetEntity: Vlan::class)]
    #[ORM\JoinTable(name: 'network_port_vlan')]
    private Collection $vlans;

    public function __construct(Asset $asset, string $name)
    {
        $this->asset = $asset;
        $this->name = $name;
        $this->ipAddresses = new ArrayCollection();
        $this->vlans = new ArrayCollection();
    }

    public static function normalizeMac(?string $mac): ?string
    {
        if (null === $mac) {
            return null;
        }
        $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $mac));
        if (12 !== \strlen($hex) || '000000000000' === $hex) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    /** Chave de reconciliação: ifIndex > MAC+nome > nome. */
    public function reconciliationKey(): string
    {
        if (null !== $this->ifIndex) {
            return 'if|'.$this->ifIndex;
        }

        return ($this->macAddress ?? '').'|'.mb_strtolower($this->name);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMacAddress(): ?string
    {
        return $this->macAddress;
    }

    public function setMacAddress(?string $macAddress): void
    {
        $this->macAddress = self::normalizeMac($macAddress);
    }

    public function getIfIndex(): ?int
    {
        return $this->ifIndex;
    }

    public function setIfIndex(?int $ifIndex): void
    {
        $this->ifIndex = $ifIndex;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    public function getSpeedMbps(): ?int
    {
        return $this->speedMbps;
    }

    public function setSpeedMbps(?int $speedMbps): void
    {
        $this->speedMbps = $speedMbps;
    }

    public function getOperStatus(): ?string
    {
        return $this->operStatus;
    }

    public function setOperStatus(?string $operStatus): void
    {
        $this->operStatus = $operStatus;
    }

    public function isVirtual(): bool
    {
        return $this->virtual;
    }

    public function setVirtual(bool $virtual): void
    {
        $this->virtual = $virtual;
    }

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    public function setDynamic(bool $dynamic): void
    {
        $this->dynamic = $dynamic;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, mixed> $attributes */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = array_filter($attributes, static fn (mixed $v): bool => null !== $v && '' !== $v);
    }

    /** @return Collection<int, IpAddress> */
    public function getIpAddresses(): Collection
    {
        return $this->ipAddresses;
    }

    /**
     * Sincroniza a lista de IPs (adiciona novos, remove ausentes).
     *
     * @param array<string, ?int> $addresses endereço => prefixo
     */
    public function syncIpAddresses(array $addresses): void
    {
        $wanted = [];
        foreach ($addresses as $address => $prefix) {
            $packed = @inet_pton(trim((string) $address));
            if (false !== $packed) {
                $wanted[(string) inet_ntop($packed)] = $prefix;
            }
        }

        foreach ($this->ipAddresses->toArray() as $ip) {
            if (!\array_key_exists($ip->getAddress(), $wanted)) {
                $this->ipAddresses->removeElement($ip);
                continue;
            }
            $ip->setPrefixLength($wanted[$ip->getAddress()]);
            unset($wanted[$ip->getAddress()]);
        }

        foreach ($wanted as $address => $prefix) {
            $this->ipAddresses->add(new IpAddress($this, (string) $address, $prefix));
        }
    }

    /** @return Collection<int, Vlan> */
    public function getVlans(): Collection
    {
        return $this->vlans;
    }
}
