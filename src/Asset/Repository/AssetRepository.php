<?php

declare(strict_types=1);

namespace App\Asset\Repository;

use App\Asset\Entity\Asset;
use App\Asset\Entity\AssetType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Asset>
 */
final class AssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Asset::class);
    }

    public function findOneByHardwareUuid(string $hardwareUuid): ?Asset
    {
        return $this->findOneBy(['hardwareUuid' => strtolower($hardwareUuid), 'deletedAt' => null]);
    }

    public function findOneBySerial(string $serial, AssetType $type): ?Asset
    {
        return $this->findOneBy(['serialNumber' => $serial, 'assetType' => $type, 'deletedAt' => null]);
    }

    public function findOneByName(string $name, AssetType $type): ?Asset
    {
        return $this->findOneBy(['name' => $name, 'assetType' => $type, 'deletedAt' => null]);
    }
}
