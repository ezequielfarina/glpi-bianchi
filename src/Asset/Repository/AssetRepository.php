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

    /**
     * Ativo na lixeira com o mesmo identificador forte de hardware (UUID da placa
     * ou serial). Usado para restaurar em vez de duplicar quando o agente volta a reportar.
     */
    public function findDeletedByIdentity(?string $hardwareUuid, ?string $serial, AssetType $type): ?Asset
    {
        if (null === $hardwareUuid && null === $serial) {
            return null;
        }
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.deletedAt IS NOT NULL')
            ->orderBy('a.deletedAt', 'DESC')
            ->setMaxResults(1);
        $or = [];
        if (null !== $hardwareUuid) {
            $or[] = 'a.hardwareUuid = :uuid';
            $qb->setParameter('uuid', strtolower($hardwareUuid));
        }
        if (null !== $serial) {
            $or[] = '(a.serialNumber = :serial AND a.assetType = :type)';
            $qb->setParameter('serial', $serial)->setParameter('type', $type);
        }

        /** @var Asset|null */
        return $qb->andWhere(implode(' OR ', $or))->getQuery()->getOneOrNullResult();
    }

    public function findOneByName(string $name, AssetType $type): ?Asset
    {
        return $this->findOneBy(['name' => $name, 'assetType' => $type, 'deletedAt' => null]);
    }
}
