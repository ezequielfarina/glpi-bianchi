<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tools;

use App\Tools\Scanner\DeviceClassifier;
use App\Tools\Scanner\NmapHostParser;
use App\Tools\Scanner\NmapRunner;
use App\Tools\Scanner\ScanProfile;
use App\Tools\Scanner\ScanTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScannerUnitTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../Fixtures/nmap/';

    /** @return iterable<array{string, string, string|null}> */
    public static function targets(): iterable
    {
        yield 'normaliza host para rede' => ['192.168.10.77/24', 'standard', '192.168.10.0/24'];
        yield 'IP único' => ['10.1.2.3', 'full', '10.1.2.3/32'];
        yield 'CGNAT' => ['100.64.5.0/24', 'standard', '100.64.5.0/24'];
        yield 'pública recusada' => ['8.8.8.0/24', 'discovery', null];
        yield '172.32 não é privada' => ['172.32.0.0/24', 'discovery', null];
        yield 'grande demais p/ padrão' => ['10.0.0.0/21', 'standard', null];
        yield '/20 ok na descoberta' => ['10.0.0.0/20', 'discovery', '10.0.0.0/20'];
        yield 'lixo' => ['192.168.1.0/24; rm -rf /', 'discovery', null];
    }

    #[DataProvider('targets')]
    public function testTargetValidation(string $input, string $profile, ?string $expected): void
    {
        if (null === $expected) {
            $this->expectException(\InvalidArgumentException::class);
        }
        self::assertSame($expected, ScanTarget::parse($input, $profile)->cidr());
    }

    public function testProfilesNeverContainUserInputExceptCommunity(): void
    {
        $args = ScanProfile::args(ScanProfile::STANDARD, true, 'c0mun1dade');
        self::assertContains('--privileged', $args);
        self::assertContains('-O', $args);
        self::assertContains('snmpcommunity=c0mun1dade', $args);
        $unpriv = ScanProfile::args(ScanProfile::FULL, false, null);
        self::assertContains('-sT', $unpriv);
        self::assertNotContains('-sU', $unpriv);
        self::assertNotContains('-O', $unpriv);
    }

    public function testParsesRealNmapXmlAndClassifies(): void
    {
        $xml = simplexml_load_file(self::FIXTURES.'localhost-services.xml');
        self::assertNotFalse($xml);
        $h = (new NmapHostParser())->parse($xml->host[0]);
        self::assertNotNull($h);
        self::assertSame('127.0.0.1', $h['ip']);
        self::assertSame([22, 80, 443, 9100], array_column($h['ports'], 'port'));
        self::assertSame('OpenSSH', $h['ports'][0]['product']);
        self::assertSame('GLPI', $h['ports'][2]['cert']['subject']);
        self::assertSame('printer', (new DeviceClassifier())->classify($h), 'porta 9100 (JetDirect) = impressora');
    }

    public function testStreamingRunnerKeepsOrderAndWaitsForSplitHost(): void
    {
        $events = [];
        $parser = new NmapHostParser();
        $runner = new NmapRunner(self::FIXTURES.'fake-nmap.sh');
        $r = $runner->run([], '192.168.10.0/24',
            function (\SimpleXMLElement $h) use (&$events, $parser): void { $events[] = $parser->parse($h)['ip'] ?? 'down'; },
            function (string $task, float $pct) use (&$events): void { $events[] = $task.':'.$pct; },
            static fn (): bool => false,
        );
        self::assertSame(0, $r['exit']);
        self::assertSame(['ARP Ping Scan:-1', 'ARP Ping Scan:10', '192.168.10.20', 'SYN Stealth Scan:55.5', '192.168.10.30', 'down'], $events);
        self::assertSame('7.95', $runner->version());
    }

    public function testClassifierRules(): void
    {
        $c = new DeviceClassifier();
        $base = ['ports' => [], 'os' => null, 'vendor' => null, 'hostname' => null, 'netbios_name' => null, 'hints' => ['types' => [], 'vendors' => []]];
        $port = static fn (int ...$p): array => array_map(static fn (int $n): array => ['port' => $n, 'proto' => 'tcp'], $p);
        self::assertSame('computer', $c->classify(['ports' => $port(3389, 445)] + $base));
        self::assertSame('server', $c->classify(['ports' => $port(53, 88, 389, 445), 'os' => 'Microsoft Windows Server 2022'] + $base));
        self::assertSame('network_equipment', $c->classify(['ports' => $port(22, 8291)] + $base));
        self::assertSame('network_equipment', $c->classify(['vendor' => 'Ubiquiti Networks', 'ports' => $port(22, 443)] + $base));
        self::assertSame('phone', $c->classify(['vendor' => 'Yealink', 'ports' => $port(80, 5060)] + $base));
        self::assertSame('camera', $c->classify(['vendor' => 'Hangzhou Hikvision', 'ports' => $port(80, 554)] + $base));
        self::assertSame('mobile', $c->classify(['vendor' => 'Apple'] + $base));
        self::assertSame('unknown', $c->classify($base));
    }

    /** Mensagem real da varredura "Rede TI": NSOCK repetido vira uma linha com contagem, sem carimbo de tempo. */
    public function testNmapStderrIsSummarized(): void
    {
        $stderr = 'NSOCK ERROR [4827.9820s] mksock_bind_addr(): Bind to 0.0.0.0:500 failed (IOD #9): Permission denied (13) '
            .'NSOCK ERROR [5027.3020s] mksock_bind_addr(): Bind to 0.0.0.0:500 failed (IOD #1229): Permission denied (13) '
            .'NSOCK ERROR [5028.5150s] mksock_bind_addr(): Bind to 0.0.0.0:500 failed (IOD #1235): Permission denied (13)';
        self::assertSame('NSOCK ERROR mksock_bind_addr(): Bind to 0.0.0.0:500 failed: Permission denied (13) (×3)', \App\Tools\MessageHandler\RunNetworkScanHandler::summarizeStderr($stderr));
    }
}
