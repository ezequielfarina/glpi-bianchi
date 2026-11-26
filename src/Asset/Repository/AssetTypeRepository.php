<?php

declare(strict_types=1);

namespace App\Asset\Repository;

use App\Asset\Entity\AssetType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssetType>
 */
final class AssetTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssetType::class);
    }

    public function getByCode(string $code): AssetType
    {
        return $this->findOneBy(['code' => $code])
            ?? throw new \RuntimeException(\sprintf('Asset type "%s" not found. Run the migrations (they seed the default types).', $code));
    }
}
