<?php

declare(strict_types=1);

namespace App\Organization\Repository;

use App\Organization\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
final class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    public function getByCode(string $code): Organization
    {
        return $this->findOneBy(['code' => $code])
            ?? throw new \RuntimeException(\sprintf('Organization "%s" not found. Run the migrations (it seeds "root").', $code));
    }

    /**
     * Toda a subárvore (inclusive o próprio nó), via operador ltree `<@`.
     *
     * @return list<int>
     */
    public function findSubtreeIds(Organization $root): array
    {
        /** @var list<int> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM organization WHERE path <@ (SELECT path FROM organization WHERE id = :id)',
            ['id' => $root->getId()],
        );
    }
}
