<?php

declare(strict_types=1);

namespace App\Identity\Command;

use App\Identity\Entity\Account;
use App\Identity\Service\AccountService;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cria conta do painel pelo terminal (primeiro administrador / recuperação).
 *
 *   bin/console app:user:create admin --role=admin --name="Administrador" --generate
 *   bin/console app:user:create maria.souza --role=editor        # pede a senha (oculta)
 *   bin/console app:user:create admin --role=admin --generate --if-none   # só se não houver contas (install.sh)
 */
#[AsCommand(name: 'app:user:create', description: 'Cria uma conta de acesso ao painel')]
final class CreateUserCommand extends Command
{
    public function __construct(private readonly AccountService $accounts, private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Login (minúsculas, ex.: maria.souza)')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'admin | editor | viewer', Account::ROLE_VIEWER)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nome de exibição')
            ->addOption('generate', null, InputOption::VALUE_NONE, 'Gera senha temporária (troca obrigatória no 1º login)')
            ->addOption('if-none', null, InputOption::VALUE_NONE, 'Não faz nada se já existir alguma conta');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('if-none') && (int) $this->db->fetchOne('SELECT count(*) FROM app_account') > 0) {
            $io->writeln('Já existem contas; nada a fazer.');

            return Command::SUCCESS;
        }
        $username = (string) $input->getArgument('username');
        $name = (string) ($input->getOption('name') ?? $username);
        $password = null;
        if (!$input->getOption('generate')) {
            $password = (string) $io->askHidden('Senha (mín. 12 caracteres; frase-senha é bem-vinda)');
            if ($password !== (string) $io->askHidden('Repita a senha')) {
                $io->error('As senhas não conferem.');

                return Command::FAILURE;
            }
        }
        try {
            $r = $this->accounts->createFromCli($username, $name, (string) $input->getOption('role'), $password);
        } catch (ValidationException $e) {
            $io->error(implode("\n", $e->errors));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Conta "%s" criada (perfil %s).', $username, $input->getOption('role')));
        if (null !== $r['temporary_password']) {
            $io->writeln(\sprintf("Senha temporária: <info>%s</info>\nGuarde-a agora: ela não é exibida de novo e deve ser trocada no primeiro login.", $r['temporary_password']));
        }

        return Command::SUCCESS;
    }
}
