<?php

declare(strict_types=1);

namespace App\Tools\Command;

use App\Tools\Alert\AlertService;
use App\Tools\Service\ScheduleService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dispara as varreduras agendadas vencidas (ADR-0013). Rodado a cada minuto por
 * `bianchi-scan-scheduler.timer`; um advisory lock impede execuções sobrepostas.
 *
 *   bin/console app:scan:tick -v
 */
#[AsCommand(name: 'app:scan:tick', description: 'Dispara varreduras agendadas vencidas e faz a limpeza de alertas antigos')]
final class ScanTickCommand extends Command
{
    private const int LOCK = 0x5CA7_71C4;

    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly AlertService $alerts,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (true !== $this->db->fetchOne('SELECT pg_try_advisory_lock(?)', [self::LOCK])) {
            $output->writeln('Outro tick em execução; saindo.', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }
        try {
            foreach ($this->schedules->tick() as $r) {
                $output->writeln($r['schedule'].': '.$r['result']);
                $this->logger->info('Scheduled scan tick', $r);
            }
            if ('00' === date('i')) {
                $n = $this->alerts->purge();
                $output->writeln("Alertas reconhecidos antigos removidos: {$n}", OutputInterface::VERBOSITY_VERBOSE);
            }
        } finally {
            $this->db->fetchOne('SELECT pg_advisory_unlock(?)', [self::LOCK]);
        }

        return Command::SUCCESS;
    }
}
