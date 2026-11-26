<?php

declare(strict_types=1);

namespace App\Inventory\Command;

use App\Inventory\Message\ProcessInventory;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reenfileira inventários já recebidos (payload guardado em raw_inventory).
 * Uso típico: após corrigir um bug no importador. Ver ADR-0005.
 *
 *   bin/console app:inventory:reprocess 42              # um inventário
 *   bin/console app:inventory:reprocess --latest        # o último de cada dispositivo
 *   bin/console app:inventory:reprocess --failed        # todos com status failed
 *   bin/console app:inventory:reprocess --device=PC-01-2026-...  # o último desse device
 */
#[AsCommand(name: 'app:inventory:reprocess', description: 'Reprocessa inventários já recebidos a partir do payload bruto')]
final class ReprocessInventoryCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'ID do raw_inventory')
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Último inventário COMPLETO de cada dispositivo')
            ->addOption('failed', null, InputOption::VALUE_NONE, 'Todos com status failed')
            ->addOption('device', null, InputOption::VALUE_REQUIRED, 'Último inventário completo do deviceid informado');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $ids */
        $ids = match (true) {
            null !== $input->getArgument('id') => [(string) $input->getArgument('id')],
            (bool) $input->getOption('failed') => $this->db->fetchFirstColumn("SELECT id FROM raw_inventory WHERE status = 'failed' ORDER BY id"),
            null !== $input->getOption('device') => $this->db->fetchFirstColumn(
                "SELECT id FROM raw_inventory WHERE device_id = ? AND NOT partial AND status <> 'rejected' ORDER BY id DESC LIMIT 1",
                [$input->getOption('device')],
            ),
            (bool) $input->getOption('latest') => $this->db->fetchFirstColumn(
                "SELECT DISTINCT ON (device_id) id FROM raw_inventory WHERE NOT partial AND status <> 'rejected' ORDER BY device_id, id DESC",
            ),
            default => [],
        };

        if ([] === $ids) {
            $io->warning('Nada para reprocessar. Informe um id, --latest, --failed ou --device=.');

            return Command::INVALID;
        }

        foreach ($ids as $id) {
            $this->db->executeStatement("UPDATE raw_inventory SET status = 'received', error = NULL WHERE id = ?", [$id]);
            $this->bus->dispatch(new ProcessInventory((string) $id));
        }

        $io->success(\sprintf('%d inventário(s) reenfileirado(s): %s', \count($ids), implode(', ', \array_slice($ids, 0, 20))));

        return Command::SUCCESS;
    }
}
