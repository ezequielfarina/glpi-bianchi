<?php

declare(strict_types=1);

namespace App\Software\Repository;

use App\Asset\Repository\ManufacturerRepository;
use App\Software\Entity\Software;
use App\Software\Entity\SoftwareVersion;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolve (busca ou cria) Software + SoftwareVersion com cache em memória.
 *
 * Um inventário Windows traz centenas de softwares; sem cache seriam 2 SELECTs
 * por item. O cache vive pelo tempo de uma mensagem (reset no handler).
 */
final class SoftwareCatalog
{
    /** @var array<string, Software> */
    private array $software = [];

    /** @var array<string, SoftwareVersion> */
    private array $versions = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManufacturerRepository $manufacturers,
    ) {
    }

    public function resolve(string $name, ?string $publisher, ?string $version, ?string $architecture): SoftwareVersion
    {
        $publisherEntity = $this->manufacturers->findOrCreate($publisher);
        $softwareKey = Software::key($name, $publisherEntity?->getNormalizedName());

        $software = $this->software[$softwareKey]
            ??= $this->em->getRepository(Software::class)->findOneBy(['normalizedKey' => $softwareKey])
            ?? $this->persist(new Software($name, $publisherEntity));

        $versionKey = SoftwareVersion::key($softwareKey, $version, $architecture);
        if (isset($this->versions[$versionKey])) {
            return $this->versions[$versionKey];
        }

        $found = null;
        if (null !== $software->getId()) {
            $found = $this->em->getRepository(SoftwareVersion::class)->findOneBy([
                'software' => $software,
                'version' => trim($version ?? ''),
                'architecture' => trim($architecture ?? ''),
            ]);
        }

        return $this->versions[$versionKey] = $found ?? $this->persist(new SoftwareVersion($software, $version, $architecture));
    }

    public function reset(): void
    {
        $this->software = [];
        $this->versions = [];
        $this->manufacturers->resetCache();
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);

        return $entity;
    }
}
