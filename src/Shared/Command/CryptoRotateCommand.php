<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Attachment\Service\AttachmentStorage;
use App\Shared\Doctrine\Type\SealedJsonType;
use App\Shared\Security\SecretBox;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Criptografia em repouso (ADR-0019) — idempotente, pode rodar a qualquer momento:
 *  1. regrava em v2 (XChaCha20-Poly1305 + contexto) e com a chave ATUAL todo segredo de coluna
 *     ainda no formato antigo ou cifrado por uma chave de DATA_ENCRYPTION_KEYS_OLD;
 *  2. cifra os anexos gravados antes da ADR-0019 e regrava as chaves de arquivo;
 *  3. cifra os payloads brutos de inventário antigos (payload_legacy → payload_sealed).
 *
 * Rotação de chave: gere a nova (`openssl rand -base64 32`), mova a atual para
 * DATA_ENCRYPTION_KEYS_OLD, ponha a nova em DATA_ENCRYPTION_KEY, rode este comando e,
 * com "0 pendentes", remova a antiga do .env.local (e do cofre de backup só depois do próximo backup).
 */
#[AsCommand(name: 'app:crypto:rotate', description: 'Cifra dados antigos e regrava tudo com a chave atual (ADR-0019)')]
final class CryptoRotateCommand extends Command
{
    /** tabela, coluna, contexto (com {id} = chave primária), chave primária */
    private const array COLUMNS = [
        ['app_account', 'totp_secret', 'account.totp:{id}', 'id'],
        ['license', 'license_key', 'license.key', 'id'],
        ['notification_channel', 'target', 'channel.target', 'id'],
        ['scan_schedule', 'snmp_community', 'scan.community', 'id'],
        ['unifi_controller', 'secret', 'unifi.secret:{id}', 'id'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $box,
        private readonly AttachmentStorage $attachments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Só conta o que seria regravado');
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Com --dry-run: sai com erro se houver algo pendente (scripts de rotação)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->box->isConfigured()) {
            $io->error('DATA_ENCRYPTION_KEY ausente ou inválida (precisa ser base64 de 32 bytes).');

            return Command::FAILURE;
        }
        $dry = (bool) $input->getOption('dry-run');
        $io->title('Criptografia em repouso — chave atual '.$this->box->currentKeyId());

        $rows = [];
        $pendingTotal = 0;
        foreach (self::COLUMNS as [$table, $col, $ctx, $pk]) {
            $pending = 0;
            foreach ($this->db->fetchAllAssociative("SELECT $pk AS pk, $col AS v FROM $table WHERE $col IS NOT NULL") as $r) {
                if (!$this->box->needsRotation((string) $r['v'])) {
                    continue;
                }
                ++$pending;
                if (!$dry) {
                    $context = str_replace('{id}', (string) $r['pk'], $ctx);
                    $this->db->executeStatement("UPDATE $table SET $col = ? WHERE $pk = ?", [$this->box->reencrypt((string) $r['v'], $context), $r['pk']]);
                }
            }
            $rows[] = ["$table.$col", $pending];
            $pendingTotal += $pending;
        }

        $legacyFiles = (int) $this->db->fetchOne('SELECT count(*) FROM attachment WHERE encryption_key IS NULL');
        $pendingTotal += $legacyFiles + $this->countStaleFileKeys();
        $files = $dry ? ['encrypted' => $legacyFiles, 'missing' => 0] : $this->attachments->encryptLegacy();
        $rows[] = ['anexos (arquivos em claro)', $files['encrypted'].($files['missing'] ? " ({$files['missing']} ausentes no disco)" : '')];
        $rows[] = ['anexos (chaves de arquivo)', $dry ? '—' : $this->attachments->rewrapKeys()];

        $legacy = (int) $this->db->fetchOne('SELECT count(*) FROM raw_inventory WHERE payload_legacy IS NOT NULL');
        $pendingTotal += $legacy;
        if ($dry) {
            foreach ($this->db->fetchFirstColumn('SELECT payload_sealed FROM raw_inventory WHERE payload_sealed IS NOT NULL') as $v) {
                $pendingTotal += $this->box->needsRotation((string) $v) ? 1 : 0;
            }
        }
        $resealed = 0;
        if (!$dry) {
            do {
                $batch = $this->db->fetchAllAssociative('SELECT id, payload_legacy::text AS p FROM raw_inventory WHERE payload_legacy IS NOT NULL ORDER BY id LIMIT 200');
                foreach ($batch as $r) {
                    $this->db->executeStatement(
                        'UPDATE raw_inventory SET payload_sealed = ?, payload_legacy = NULL WHERE id = ?',
                        [SealedJsonType::seal(json_decode((string) $r['p'], true, 512, \JSON_THROW_ON_ERROR)), $r['id']]
                    );
                    ++$resealed;
                }
            } while ([] !== $batch);
            foreach ($this->db->fetchAllAssociative('SELECT id, payload_sealed AS p FROM raw_inventory WHERE payload_sealed IS NOT NULL') as $r) {
                if ($this->box->needsRotation((string) $r['p'])) {
                    $this->db->executeStatement('UPDATE raw_inventory SET payload_sealed = ? WHERE id = ?', [SealedJsonType::seal(SealedJsonType::open((string) $r['p'])), $r['id']]);
                    ++$resealed;
                }
            }
        }
        $rows[] = ['inventários brutos', $dry ? $legacy.' em claro' : $resealed];

        $io->table(['Dado', $dry ? 'Pendentes' : 'Regravados'], $rows);
        if ($dry && $input->getOption('check') && $pendingTotal > 0) {
            $io->warning("$pendingTotal item(ns) ainda não estão com a chave atual.");

            return Command::FAILURE;
        }
        $io->success($dry ? 'Simulação: nada foi alterado.' : 'Pronto. Rode de novo com --dry-run: tudo deve aparecer com 0.');

        return Command::SUCCESS;
    }

    private function countStaleFileKeys(): int
    {
        $n = 0;
        foreach ($this->db->fetchFirstColumn('SELECT encryption_key FROM attachment WHERE encryption_key IS NOT NULL') as $k) {
            $n += $this->box->needsRotation((string) $k) ? 1 : 0;
        }

        return $n;
    }
}
