<?php

declare(strict_types=1);

namespace App\Settings\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configuração editável pelo painel (chave → valor JSON).
 * Chaves conhecidas e validação: App\Settings\Service\Settings::DEFINITIONS.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private mixed $value;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, mixed $value)
    {
        $this->name = $name;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): void
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
