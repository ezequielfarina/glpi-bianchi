<?php

declare(strict_types=1);

namespace App\Wifi\Command;

use App\Wifi\Service\IdentityService;
use App\Wifi\Service\SyncService;
use App\Wifi\Service\WlanApplier;
use App\Wifi\Unifi\UnifiException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Timer do CT (a cada 10 min): vence acessos, sincroniza controllers e reaplica o que ficou pendente (ADR-0020). */
#[AsCommand(name: 'app:wifi:sync', description: 'Sincroniza UniFi, revoga acessos vencidos e aplica pendências do filtro de MAC')]
final class WifiSyncCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly SyncService $sync,
        private readonly WlanApplier $applier,
        private readonly IdentityService $identities,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-sync', null, InputOption::VALUE_NONE, 'Não relê os controllers, só aplica pendências');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failures = 0;
        $expired = $this->identities->expireDue();
        if ([] !== $expired) {
            $io->note(\sprintf('Acessos vencidos revogados em %d rede(s).', \count($expired)));
        }
        if (!$input->getOption('no-sync')) {
            foreach ($this->db->fetchAllAssociative('SELECT id, name FROM unifi_controller WHERE enabled ORDER BY id') as $c) {
                try {
                    $r = $this->sync->sync((int) $c['id']);
                    $io->writeln(\sprintf('<info>%s</info>: %d site(s), %d rede(s)%s', $c['name'], $r['sites'], $r['wlans'], $r['drift'] > 0 ? ", {$r['drift']} MAC(s) removido(s) direto no UniFi" : ''));
                } catch (UnifiException $e) {
                    ++$failures;
                    $io->warning($c['name'].': '.$e->getMessage());
                }
            }
        }
        foreach ($this->applier->pendingWlans() as $w) {
            try {
                $r = $this->applier->apply($w);
            } catch (\RuntimeException $e) {
                $r = ['error' => $e->getMessage(), 'added' => [], 'removed' => [], 'total' => 0];
            }
            if (null !== $r['error']) {
                ++$failures;
                $io->warning("Rede #$w: ".$r['error']);
            } else {
                $io->writeln(\sprintf('Rede #%d: +%d −%d (total %d)', $w, \count($r['added']), \count($r['removed']), $r['total']));
            }
        }

        return 0 === $failures ? Command::SUCCESS : Command::FAILURE;
    }
}
