<?php

declare(strict_types=1);

namespace App\Tools\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Canal de notificação dos alertas de rede (ADR-0013): webhook JSON, Slack/Mattermost/Google Chat,
 * Microsoft Teams (Workflows) ou Telegram. `target` (URL ou token do bot) fica **cifrado**.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_channel')]
class NotificationChannel
{
    public const array KINDS = ['webhook', 'slack', 'teams', 'telegram'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(type: Types::TEXT)]
    private string $target;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $chatId = null;

    #[ORM\Column(length: 16, options: ['default' => 'warning'])]
    private string $minSeverity = 'warning';

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSentAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $kind, string $encryptedTarget)
    {
        $this->name = $name;
        $this->kind = $kind;
        $this->target = $encryptedTarget;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
