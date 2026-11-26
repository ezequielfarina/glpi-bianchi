<?php

declare(strict_types=1);

namespace App\Inventory\MessageHandler;

use App\Inventory\Entity\RawInventory;
use App\Inventory\Enum\RawInventoryStatus;
use App\Inventory\Import\ComputerImporter;
use App\Inventory\Message\ProcessInventory;
use App\Software\Repository\SoftwareCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker: processa um RawInventory em uma única transação.
 *
 * Concorrência: `pg_advisory_xact_lock(hashtext(deviceid))` serializa inventários
 * do MESMO dispositivo (ex.: agente reenviando após timeout) sem bloquear os demais.
 * O lock é liberado automaticamente no COMMIT/ROLLBACK.
 *
 * Falha → status "failed" + exceção relançada → retry do Messenger (3x, backoff) →
 * fila "failed" (`bin/console messenger:failed:show`).
 */
#[AsMessageHandler]
final readonly class ProcessInventoryHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private ComputerImporter $computerImporter,
        private SoftwareCatalog $catalog,
        #[Autowire(service: 'monolog.logger.inventory')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessInventory $message): void
    {
        $raw = $this->em->find(RawInventory::class, $message->rawInventoryId);
        if (null === $raw || RawInventoryStatus::Processed === $raw->getStatus() || RawInventoryStatus::Rejected === $raw->getStatus()) {
            return; // idempotente: reentrega de mensagem já processada
        }

        $started = hrtime(true);
        $this->catalog->reset();

        try {
            $result = $this->em->wrapInTransaction(function () use ($raw, $started) {
                $this->em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(?))', [$raw->getDeviceId()]);
                $raw->markProcessing();

                $result = match ($raw->getItemtype()) {
                    'Computer' => $this->computerImporter->import($raw),
                    default => throw new \LogicException('Unsupported itemtype '.$raw->getItemtype()),
                };

                $raw->markProcessed($result->asset, (int) ((hrtime(true) - $started) / 1_000_000), $result->created, $result->changes);

                return $result;
            });
        } catch (\Throwable $e) {
            $this->markFailed($message->rawInventoryId, $e);
            throw $e;
        } finally {
            $this->em->clear();
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $this->logger->info('Inventory processed', [
            'raw_inventory_id' => $message->rawInventoryId,
            'device_id' => $raw->getDeviceId(),
            'asset_uuid' => (string) $result->asset->getUuid(),
            'created' => $result->created,
            'changes' => $result->changes,
            'duration_ms' => $durationMs,
        ]);
    }

    private function markFailed(string $id, \Throwable $e): void
    {
        // EntityManager pode estar fechado após erro na transação
        if (!$this->em->isOpen()) {
            $this->registry->resetManager();
        }
        $this->em->getConnection()->executeStatement(
            "UPDATE raw_inventory SET status = 'failed', error = ?, processed_at = NOW() WHERE id = ?",
            [mb_substr($e::class.': '.$e->getMessage(), 0, 10000), $id],
        );
        $this->logger->error('Inventory processing failed', ['raw_inventory_id' => $id, 'exception' => $e]);
    }
}
