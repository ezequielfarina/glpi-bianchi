<?php

declare(strict_types=1);

namespace App\Inventory\Import;

use App\Asset\Entity\Asset;
use App\Asset\Entity\AssetType;
use App\Asset\Repository\AssetTypeRepository;
use App\Asset\Service\InventoryNumberGenerator;
use App\Inventory\Entity\RawInventory;
use App\Inventory\Import\Section\SectionImporter;
use App\Organization\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Importa um inventário `itemtype=Computer` (desktops, notebooks, servidores, VMs).
 *
 * Fluxo: match/cria ativo → seções (ordem por priority) → marca inventariado → vincula agente.
 * Não faz flush: a transação é controlada pelo ProcessInventoryHandler.
 */
final readonly class ComputerImporter
{
    /**
     * @param iterable<SectionImporter> $sections
     */
    public function __construct(
        private EntityManagerInterface $em,
        private AssetMatcher $matcher,
        private AssetTypeRepository $assetTypes,
        private OrganizationRepository $organizations,
        private InventoryNumberGenerator $inventoryNumbers,
        #[AutowireIterator('app.inventory.section', defaultPriorityMethod: 'priority')]
        private iterable $sections,
        private string $defaultOrganizationCode,
    ) {
    }

    public function import(RawInventory $raw): ImportResult
    {
        $content = $raw->getPayload()['content'] ?? [];
        $content = \is_array($content) ? $content : [];
        $type = $this->assetTypes->getByCode(AssetType::COMPUTER);
        $agent = $raw->getAgent();

        $asset = $this->matcher->match($content, $type, $agent);
        $isNew = null === $asset;
        if ($isNew) {
            $name = Value::str($content['hardware']['name'] ?? null) ?? $raw->getDeviceId();
            $organization = $agent?->getOrganization() ?? $this->organizations->getByCode($this->defaultOrganizationCode);
            $asset = new Asset($organization, $type, $name);
            $asset->setInventoryNumber($this->inventoryNumbers->next());
            $this->em->persist($asset);
        }

        $context = new ImportContext($raw, $asset, $isNew, $this->em);
        $executed = [];
        foreach ($this->sections as $section) {
            $present = array_intersect($section->sections(), array_keys($content));
            // parcial: só o que veio; completo: seção ausente = vazia (remove dinâmicos)
            if ([] === $present && $context->isPartial()) {
                continue;
            }
            $section->import($content, $context);
            $executed[] = $section::class;
        }

        $asset->markInventoried(new \DateTimeImmutable());
        $agent?->setAsset($asset);

        return new ImportResult($asset, $isNew, $context->changes(), $executed);
    }
}
