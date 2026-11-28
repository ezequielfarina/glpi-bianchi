<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tools;

use App\Tests\Integration\Support\LogsIn;
use App\Tools\Message\RunNetworkScan;
use App\Tools\MessageHandler\RunNetworkScanHandler;
use App\Tools\Service\ScheduleService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * nmap morto por SIGTERM vindo de fora (reinício do worker num deploy) não é "falha da rede":
 * vira "interrompida", mantém os hosts já vistos e o agendamento roda de novo em 5 minutos.
 * Varredura órfã (worker morto sem registrar o fim) é encerrada pelo tick.
 */
final class ScanInterruptionTest extends WebTestCase
{
    use LogsIn;

    private const string FLAG = __DIR__.'/../../Fixtures/nmap/.sigterm';

    protected function tearDown(): void
    {
        @unlink(self::FLAG);
        parent::tearDown();
    }

    public function testExternalSigtermIsReportedAsInterruptionAndRescheduled(): void
    {
        $client = self::createClient();
        $this->loginAs($client, 'editor');
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $client->request('POST', '/api/ui/tools/schedules', server: self::UI + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'name' => 'Rede TI', 'target' => '192.168.10.0/24', 'profile' => 'full', 'frequency' => 'daily', 'run_at' => '07:30',
        ]));
        self::assertResponseStatusCodeSame(201);
        $schedule = (int) (json_decode((string) $client->getResponse()->getContent(), true)['id'] ?? 0);
        $db->executeStatement("UPDATE scan_schedule SET next_run_at = now() - interval '1 minute' WHERE id = ?", [$schedule]);
        self::getContainer()->get(ScheduleService::class)->tick();
        $scan = (int) $db->fetchOne('SELECT max(id) FROM network_scan WHERE schedule_id = ?', [$schedule]);

        touch(self::FLAG);
        self::getContainer()->get(RunNetworkScanHandler::class)(new RunNetworkScan($scan));

        $row = $db->fetchAssociative('SELECT status, error FROM network_scan WHERE id = ?', [$scan]);
        self::assertSame('failed', $row['status'] ?? null);
        self::assertStringContainsString('interrompida', (string) $row['error']);
        self::assertStringContainsString('sinal 15', (string) $row['error']);
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM network_scan_host WHERE scan_id = ?', [$scan]), 'host visto antes do SIGTERM fica');
        self::assertTrue((bool) $db->fetchOne("SELECT next_run_at <= now() + interval '5 minutes' FROM scan_schedule WHERE id = ?", [$schedule]));
        self::assertSame(0, (int) $db->fetchOne("SELECT count(*) FROM network_alert WHERE kind = 'scan_failed'"), 'interrupção não dispara alerta de falha');
    }

    public function testOrphanRunningScanIsClosedByTick(): void
    {
        self::createClient();
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("INSERT INTO network_scan (target, profile, options, status, progress, hosts_up, requested_by, created_at, started_at)
            VALUES ('192.168.20.0/24', 'standard', '{}', 'running', 40, 3, 'teste', now() - interval '2 hours', now() - interval '2 hours')");
        $log = self::getContainer()->get(ScheduleService::class)->tick();
        self::assertStringContainsString('órfã', $log[0]['result'] ?? '');
        self::assertSame('failed', $db->fetchOne("SELECT status FROM network_scan WHERE target = '192.168.20.0/24'"));
    }
}
