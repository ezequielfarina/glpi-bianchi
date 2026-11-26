<?php

declare(strict_types=1);

namespace App\Asset\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Asset\Repository\AssetTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tipo de ativo (Computer, Monitor, NetworkEquipment...). Cadastro de dados,
 * não de código: novos tipos são linhas nesta tabela — mesmo conceito dos
 * "Generic Assets" (AssetDefinition) do GLPI 11/12. Ver ADR-0003.
 *
 * `code` casa com o `itemtype` enviado pelo GLPI Agent, em snake_case.
 */
#[ORM\Entity(repositoryClass: AssetTypeRepository::class)]
#[ORM\Table(name: 'asset_type')]
#[ORM\UniqueConstraint(name: 'uniq_asset_type_code', columns: ['code'])]
#[ApiResource(operations: [new Get(), new GetCollection()])]
class AssetType
{
    public const string COMPUTER = 'computer';
    public const string MONITOR = 'monitor';
    public const string NETWORK_EQUIPMENT = 'network_equipment';
    public const string PRINTER = 'printer';
    public const string PHONE = 'phone';
    public const string PERIPHERAL = 'peripheral';
    public const string PDU = 'pdu';
    public const string RACK = 'rack';
    public const string ENCLOSURE = 'enclosure';
    public const string UNMANAGED = 'unmanaged';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $icon = null;

    /** Recebe dados do GLPI Agent (inventário automático). */
    #[ORM\Column(options: ['default' => false])]
    private bool $inventoriable = false;

    public function __construct(string $code, string $label, bool $inventoriable = false)
    {
        $this->code = $code;
        $this->label = $label;
        $this->inventoriable = $inventoriable;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function isInventoriable(): bool
    {
        return $this->inventoriable;
    }
}
