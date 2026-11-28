<?php

declare(strict_types=1);

namespace App\Vpn\Entity;

use App\Circuit\Entity\Circuit;
use App\Dcim\Entity\Device;
use App\Shared\Entity\TimestampableTrait;
use App\Supplier\Entity\Supplier;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Túnel IPsec (fase 1) de um FortiGate, importado da configuração (ADR-0022). A PSK nunca é guardada:
 * só `pskLocation` (onde ela está, ex.: cofre). Regras e escrita: src/Vpn/Service (DBAL).
 * Referência de modelo: netbox/vpn/models/tunnels.py e crypto.py (IKEPolicy/IPSecPolicy), achatado no estilo FortiOS.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vpn_tunnel')]
#[ORM\UniqueConstraint(name: 'uniq_vpn_tunnel', columns: ['device_id', 'vdom', 'name'])]
#[ORM\Index(name: 'idx_vpn_tunnel_remote', columns: ['remote_gw'])]
#[ORM\HasLifecycleCallbacks]
class VpnTunnel
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    /** VDOM do FortiGate ('' = sem VDOM) */
    #[ORM\Column(length: 32, options: ['default' => ''])]
    private string $vdom = '';

    /** nome da fase 1 (= interface do túnel no modo interface) */
    #[ORM\Column(length: 64)]
    private string $name;

    /** interface | policy */
    #[ORM\Column(length: 10, options: ['default' => 'interface'])]
    private string $mode = 'interface';

    /** site_to_site | dialup | hub | spoke */
    #[ORM\Column(length: 16, options: ['default' => 'site_to_site'])]
    private string $topology = 'site_to_site';

    /** active | disabled | planned | decommissioning */
    #[ORM\Column(length: 16, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $localInterface = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $localIp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $remoteGw = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $ikeVersion = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $ikeMode = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $authMethod = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $p1Proposal = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $p1Dhgroups = null;

    #[ORM\Column(nullable: true)]
    private ?int $p1Keylife = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $dpd = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $natTraversal = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tunnelIp = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tunnelRemoteIp = null;

    /** @var list<string> redes roteadas pelo túnel (config router static) */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $routes = [];

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sdwanZone = null;

    /** @var array<string, string> demais "set" da fase 1 (sem segredos) */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $params = [];

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $peerTunnel = null;

    /** quem está do outro lado (filial, banco, parceiro…) */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $peerLabel = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Supplier $supplier = null;

    #[ORM\ManyToOne(targetEntity: Circuit::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Circuit $circuit = null;

    /** onde a PSK está guardada (nunca a PSK) */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $pskLocation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    /** quantos parâmetros secretos foram descartados na última importação */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $secretsRemoved = 0;

    /** estava na última configuração importada deste FortiGate */
    #[ORM\Column(options: ['default' => true])]
    private bool $present = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $importedAt = null;

    public function __construct(Device $device, string $name)
    {
        $this->device = $device;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /** @return array<string, string> */
    public function getParams(): array
    {
        return $this->params;
    }

    public function getPeerTunnel(): ?self
    {
        return $this->peerTunnel;
    }

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function getCircuit(): ?Circuit
    {
        return $this->circuit;
    }
}
