<?php

declare(strict_types=1);

namespace App\License\Entity;

use App\Asset\Entity\Asset;
use App\Identity\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Assento de licença consumido por um ativo OU por um usuário
 * (CHECK chk_license_assignment_target no banco garante exatamente um).
 */
#[ORM\Entity]
#[ORM\Table(name: 'license_assignment')]
#[ORM\UniqueConstraint(name: 'uniq_license_assignment_asset', columns: ['license_id', 'asset_id'])]
#[ORM\UniqueConstraint(name: 'uniq_license_assignment_user', columns: ['license_id', 'user_id'])]
class LicenseAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private License $license;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Asset $asset;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?User $user;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $assignedAt;

    public function __construct(License $license, ?Asset $asset, ?User $user)
    {
        if ((null === $asset) === (null === $user)) {
            throw new \InvalidArgumentException('Assignment requires exactly one of asset or user.');
        }
        $this->license = $license;
        $this->asset = $asset;
        $this->user = $user;
        $this->assignedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getLicense(): License { return $this->license; }
    public function getAsset(): ?Asset { return $this->asset; }
    public function getUser(): ?User { return $this->user; }
    public function getNotes(): ?string { return $this->notes; }
    public function getAssignedAt(): \DateTimeImmutable { return $this->assignedAt; }
}
