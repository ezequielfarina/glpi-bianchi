<?php

declare(strict_types=1);

namespace App\Identity\Command;

use App\Identity\Service\AccountService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recuperação de acesso pelo terminal do CT (quem tem root no servidor):
 *
 *   bin/console app:user:recover admin --password   # nova senha temporária
 *   bin/console app:user:recover admin --mfa        # desliga o 2FA (celular perdido)
 *   bin/console app:user:recover admin --unlock     # libera bloqueio por tentativas
 *   bin/console app:user:recover admin --enable     # reativa conta desativada
 */
#[AsCommand(name: 'app:user:recover', description: 'Recupera acesso: senha temporária, reset de 2FA, desbloqueio')]
final class RecoverUserCommand extends Command
{
    public function __construct(private readonly AccountService $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED)
            ->addOption('password', null, InputOption::VALUE_NONE, 'Gera senha temporária (encerra sessões)')
            ->addOption('mfa', null, InputOption::VALUE_NONE, 'Desliga a verificação em duas etapas')
            ->addOption('unlock', null, InputOption::VALUE_NONE, 'Zera o bloqueio por tentativas')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Reativa a conta');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');
        $id = $this->accounts->idByUsername($username);
        if (null === $id) {
            $io->error(\sprintf('Conta "%s" não encontrada.', $username));

            return Command::FAILURE;
        }
        $did = false;
        if ($input->getOption('enable')) {
            $this->accounts->update($id, ['active' => true], 0, 'console');
            $io->writeln('Conta reativada.');
            $did = true;
        }
        if ($input->getOption('unlock')) {
            $this->accounts->unlock($id, 'console');
            $io->writeln('Bloqueio por tentativas zerado.');
            $did = true;
        }
        if ($input->getOption('mfa')) {
            $this->accounts->resetMfa($id, 'console');
            $io->writeln('Verificação em duas etapas desligada (pode ser reativada em "Minha conta").');
            $did = true;
        }
        if ($input->getOption('password')) {
            $tmp = $this->accounts->resetPassword($id, 'console');
            $io->writeln(\sprintf('Senha temporária: <info>%s</info> (troca obrigatória no próximo login).', $tmp));
            $did = true;
        }
        if (!$did) {
            $io->warning('Nada a fazer: use --password, --mfa, --unlock e/ou --enable.');

            return Command::INVALID;
        }

        return Command::SUCCESS;
    }
}
