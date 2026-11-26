<?php

declare(strict_types=1);

namespace App\Network\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Endereço IP (v4 ou v6) associado a uma porta.
 *
 * Armazenado como texto canônico (inet_pton/ntop) + prefixo. Fase 2: migrar para
 * o tipo nativo `inet` para usar operadores de sub-rede (<<=) em relatórios.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ip_address')]
#[ORM\UniqueConstraint(name: 'uniq_ip_port_address', columns: ['network_port_id', 'address'])]
#[ORM\Index(name: 'idx_ip_address', columns: ['address'])]
#[ApiResource(operations: [new Get(), new GetCollection()])]
class IpAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'ipAddresses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NetworkPort $networkPort;

    #[ORM\Column(length: 45)]
    private string $address;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $prefixLength;

    /** 4 ou 6 */
    #[ORM\Column(type: 'smallint')]
    private int $version;

    public function __construct(NetworkPort $networkPort, string $address, ?int $prefixLength = null)
    {
        $packed = @inet_pton(trim($address));
        if (false === $packed) {
            throw new \InvalidArgumentException(\sprintf('Invalid IP address "%s".', $address));
        }
        $this->networkPort = $networkPort;
        $this->address = (string) inet_ntop($packed);
        $this->version = 4 === \strlen($packed) ? 4 : 6;
        $this->prefixLength = $prefixLength;
    }

    /** Converte máscara pontuada (255.255.255.0) em comprimento de prefixo (24). */
    public static function maskToPrefix(?string $mask): ?int
    {
        if (null === $mask || '' === $mask) {
            return null;
        }
        if (ctype_digit($mask)) {
            return (int) $mask;
        }
        $long = ip2long($mask);

        return false === $long ? null : substr_count(decbin($long), '1');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNetworkPort(): NetworkPort
    {
        return $this->networkPort;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getPrefixLength(): ?int
    {
        return $this->prefixLength;
    }

    public function setPrefixLength(?int $prefixLength): void
    {
        $this->prefixLength = $prefixLength;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
