<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Inventory\Entity\Agent;
use App\Inventory\Entity\RawInventory;
use App\Inventory\Enum\RawInventoryStatus;
use App\Inventory\Message\ProcessInventory;
use App\Inventory\Protocol\AgentRequest;
use App\Inventory\Protocol\ProtocolException;
use Doctrine\ORM\EntityManagerInterface;
use Glpi\Inventory\Converter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lado síncrono da ingestão: valida contra o schema oficial, grava o payload
 * bruto e enfileira. Deve ser rápido — o agente aguarda a resposta. Ver ADR-0005.
 */
final readonly class InventoryReceiver
{
    /** itemtypes com importador implementado (os demais são aceitos e marcados "rejected"). */
    public const array SUPPORTED_ITEMTYPES = ['Computer'];

    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private Converter $converter,
        #[Autowire(service: 'monolog.logger.inventory')]
        private LoggerInterface $logger,
    ) {
    }

    public function receive(AgentRequest $request, Agent $agent): RawInventory
    {
        $this->validate($request);

        $raw = new RawInventory(
            agent: $agent,
            deviceId: $request->deviceId,
            itemtype: $request->itemtype(),
            sourceFormat: $request->format->value,
            partial: $request->isPartial(),
            payload: $request->data,
            sizeBytes: $request->rawSize,
        );

        if (!\in_array($request->itemtype(), self::SUPPORTED_ITEMTYPES, true)) {
            $raw->markRejected(\sprintf('Itemtype "%s" not supported yet (supported: %s).', $request->itemtype(), implode(', ', self::SUPPORTED_ITEMTYPES)));
        }

        $this->em->persist($raw);
        $this->em->flush();

        if (RawInventoryStatus::Received === $raw->getStatus()) {
            $this->bus->dispatch(new ProcessInventory((string) $raw->getId()));
        }

        $this->logger->info('Inventory received', [
            'device_id' => $request->deviceId,
            'raw_inventory_id' => $raw->getId(),
            'itemtype' => $request->itemtype(),
            'format' => $request->format->value,
            'partial' => $request->isPartial(),
            'bytes' => $request->rawSize,
            'status' => $raw->getStatus()->value,
        ]);

        return $raw;
    }

    /**
     * Valida contra o JSON Schema oficial do glpi-project/inventory_format.
     * Erro → HTTP 400, igual ao GLPI ("JSON does not validate").
     */
    private function validate(AgentRequest $request): void
    {
        try {
            $object = json_decode(json_encode($request->data, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
            $this->converter->getSchema()->validate($object);
        } catch (\JsonException $e) {
            throw new ProtocolException('Invalid JSON payload.', 400, $e);
        } catch (\RuntimeException $e) {
            throw new ProtocolException((string) preg_replace('|\$ref\[file~2//.*?inventory\.schema\.json\]|', '$ref[inventory.schema.json]', $e->getMessage()), 400, $e);
        }
    }
}
