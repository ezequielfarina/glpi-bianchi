<?php

declare(strict_types=1);

namespace App\Wifi\Entity;

use App\Shared\Entity\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Controller UniFi Network (VM self-hosted, UniFi OS ou nuvem via api.ui.com) de onde vêm sites e redes Wi-Fi.
 * Segredo (senha do admin local ou API key) cifrado com o SecretBox, contexto "unifi.secret:<id>".
 *
 * Regras e escrita: serviços em src/Wifi/Service (DBAL). Ver ADR-0020.
 */
#[ORM\Entity]
#[ORM\Table(name: 'unifi_controller')]
#[ORM\UniqueConstraint(name: 'uniq_unifi_controller_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class UnifiController
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    /** https://IP:8443 (clássico), https://IP:11443 (UniFi OS Server), https://IP (console) ou https://api.ui.com (nuvem) */
    #[ORM\Column(length: 255)]
    private string $baseUrl;

    /** local (admin local) | api_key (UniFi OS) | cloud (Site Manager, experimental) */
    #[ORM\Column(length: 20)]
    private string $authMode;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $username = null;

    /** senha ou API key cifrada */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $secret = null;

    /** id do console no unifi.ui.com (modo cloud) */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $cloudHostId = null;

    /** valida o certificado pela CA do sistema */
    #[ORM\Column(options: ['default' => true])]
    private bool $verifyTls = true;

    /** pin-sha256 (base64) da chave pública do certificado: confiança fixa sem desligar a verificação */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tlsPin = null;

    /** unifi_os | classic (detectado) */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $platform = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $version = null;

    /** grava "pessoa — aparelho" como nome do cliente no UniFi */
    #[ORM\Column(options: ['default' => true])]
    private bool $writeClientAlias = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    public function __construct(string $name, string $baseUrl, string $authMode)
    {
        $this->name = $name;
        $this->baseUrl = $baseUrl;
        $this->authMode = $authMode;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getAuthMode(): string
    {
        return $this->authMode;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function getCloudHostId(): ?string
    {
        return $this->cloudHostId;
    }

    public function isVerifyTls(): bool
    {
        return $this->verifyTls;
    }

    public function getTlsPin(): ?string
    {
        return $this->tlsPin;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function isWriteClientAlias(): bool
    {
        return $this->writeClientAlias;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getLastSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
