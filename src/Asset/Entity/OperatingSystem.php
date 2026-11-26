<?php

declare(strict_types=1);

namespace App\Asset\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Sistema operacional instalado — embutido na tabela `asset` (colunas os_*).
 *
 * No GLPI é uma tabela de ligação (glpi_items_operatingsystems) porque permite
 * múltiplos SOs por item; na prática o agente reporta um só. Embutir elimina
 * um JOIN em toda listagem. Ver ADR-0003.
 */
#[ORM\Embeddable]
class OperatingSystem
{
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fullName = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $version = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $servicePack = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $architecture = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $kernelVersion = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $installedAt = null;

    public function __construct(
        ?string $name = null,
        ?string $fullName = null,
        ?string $version = null,
        ?string $servicePack = null,
        ?string $architecture = null,
        ?string $kernelVersion = null,
        ?\DateTimeImmutable $installedAt = null,
    ) {
        $this->name = $name;
        $this->fullName = $fullName;
        $this->version = $version;
        $this->servicePack = $servicePack;
        $this->architecture = $architecture;
        $this->kernelVersion = $kernelVersion;
        $this->installedAt = $installedAt;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function getServicePack(): ?string
    {
        return $this->servicePack;
    }

    public function getArchitecture(): ?string
    {
        return $this->architecture;
    }

    public function getKernelVersion(): ?string
    {
        return $this->kernelVersion;
    }

    public function getInstalledAt(): ?\DateTimeImmutable
    {
        return $this->installedAt;
    }

    /** Comparação por valor — evita UPDATE/histórico quando nada mudou. */
    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->fullName === $other->fullName
            && $this->version === $other->version
            && $this->servicePack === $other->servicePack
            && $this->architecture === $other->architecture
            && $this->kernelVersion === $other->kernelVersion;
    }

    /** Representação legível e completa, usada no histórico. */
    public function fingerprint(): ?string
    {
        $parts = array_filter([
            $this->fullName ?? $this->name,
            $this->version,
            $this->servicePack,
            $this->architecture,
            null === $this->kernelVersion ? null : 'kernel '.$this->kernelVersion,
        ]);

        return [] === $parts ? null : implode(' | ', $parts);
    }

    public function __toString(): string
    {
        return trim(($this->fullName ?? $this->name ?? '').' '.($this->version ?? ''));
    }
}
