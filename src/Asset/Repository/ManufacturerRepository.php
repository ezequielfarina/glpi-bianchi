<?php

declare(strict_types=1);

namespace App\Asset\Repository;

use App\Asset\Entity\Manufacturer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Manufacturer>
 */
final class ManufacturerRepository extends ServiceEntityRepository
{
    /**
     * Cache por request/mensagem: um inventário referencia o mesmo fabricante dezenas de vezes.
     *
     * @var array<string, Manufacturer>
     */
    private array $cache = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Manufacturer::class);
    }

    /**
     * Busca ou cria (sem flush). Nome vazio/genérico → null.
     */
    public function findOrCreate(?string $name): ?Manufacturer
    {
        if (null === $name) {
            return null;
        }
        $key = Manufacturer::normalize($name);
        if ('' === $key || \in_array($key, ['unknown', 'n/a', 'to be filled by o.e.m.', 'system manufacturer', 'default string'], true)) {
            return null;
        }

        if (isset($this->cache[$key]) && $this->getEntityManager()->contains($this->cache[$key])) {
            return $this->cache[$key];
        }

        $manufacturer = $this->findOneBy(['normalizedName' => $key]);
        if (null === $manufacturer) {
            $manufacturer = new Manufacturer($name);
            $this->getEntityManager()->persist($manufacturer);
        }

        return $this->cache[$key] = $manufacturer;
    }

    public function resetCache(): void
    {
        $this->cache = [];
    }
}
