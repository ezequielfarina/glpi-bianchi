<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tools;

use App\Tools\Alert\ChannelService;
use App\Tools\Alert\Notifier;
use App\Tools\Net\DiagnosticsService;
use App\Tools\Net\Host;
use App\Tools\Net\LookupService;
use App\Tools\Scanner\ArpScanner;
use App\Tools\Scanner\MacVendors;
use App\Tools\Service\ScheduleService;
use App\Ui\Write\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0013: partes puras do monitoramento, conflitos e diagnóstico (sem banco, sem rede).
 */
final class MonitorUnitTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function hosts(): iterable
    {
        yield 'ipv4' => ['10.0.0.1', '10.0.0.1'];
        yield 'ipv6' => ['fe80::1', 'fe80::1'];
        yield 'fqdn com ponto final' => ['FW.Bianchi.Local.', 'fw.bianchi.local'];
        yield 'SRV do AD' => ['_ldap._tcp.dc._msdcs.bianchi.local', '_ldap._tcp.dc._msdcs.bianchi.local'];
        yield 'opção de linha de comando' => ['-oX', null];
        yield 'injeção' => ['x;rm -rf /', null];
        yield 'espaço' => ['a b', null];
        yield 'IP truncado' => ['1.2.3', null];
    }

    #[DataProvider('hosts')]
    public function testHostValidation(string $input, ?string $expected): void
    {
        if (null === $expected) {
            $this->expectException(\InvalidArgumentException::class);
        }
        self::assertSame($expected, Host::parse($input));
    }

    public function testSpecialRanges(): void
    {
        self::assertSame('Privado (RFC 1918)', Host::specialRange('172.20.1.1'));
        self::assertSame('CGNAT (RFC 6598)', Host::specialRange('100.70.0.1'));
        self::assertNull(Host::specialRange('172.32.0.1'));
        self::assertNull(Host::specialRange('8.8.8.8'));
    }

    public function testParsePorts(): void
    {
        self::assertSame([22, 80, 443, 8000, 8001, 8002], DiagnosticsService::parsePorts('22, 80;443 8000-8002 22'));
        foreach (['', 'abc', '70000', '1-40'] as $bad) {
            try {
                DiagnosticsService::parsePorts($bad);
                self::fail("aceitou {$bad}");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testParseDig(): void
    {
        $out = <<<'TXT'
            ;; Got answer:
            ;; ->>HEADER<<- opcode: QUERY, status: NOERROR, id: 4242
            ;; flags: qr aa rd ra; QUERY: 1, ANSWER: 2, AUTHORITY: 0, ADDITIONAL: 1

            ;; ANSWER SECTION:
            bianchi.local.		600	IN	MX	10 mail.bianchi.local.
            bianchi.local.		600	IN	TXT	"v=spf1 mx -all"

            ;; Query time: 3 msec
            ;; SERVER: 192.168.110.10#53(192.168.110.10) (UDP)
            TXT;
        $r = LookupService::parseDig($out);
        self::assertSame('NOERROR', $r['status']);
        self::assertContains('aa', $r['flags']);
        self::assertSame(3, $r['ms']);
        self::assertSame('192.168.110.10', $r['via']);
        self::assertSame(['MX', 'TXT'], array_column($r['answers'], 'type'));
        self::assertSame('"v=spf1 mx -all"', $r['answers'][1]['data']);
        self::assertSame('TIMEOUT', LookupService::parseDig(';; communications error to 10.0.0.9#53: timed out')['status']);
    }

    public function testRdapSummary(): void
    {
        $d = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/rdap/domain-br.json'), true);
        $s = LookupService::summarizeRdap($d);
        self::assertSame('exemplo.com.br', $s['name']);
        self::assertSame(['a.sec.dns.br', 'b.sec.dns.br'], $s['nameservers']);
        self::assertTrue($s['dnssec']);
        self::assertSame('2027-04-10T12:00:00Z', $s['expires']);
        self::assertSame(['registrant'], $s['entities'][0]['roles']);
        self::assertSame('BR', $s['entities'][0]['country']);
        self::assertSame('Fulano de Tal', $s['entities'][1]['name']); // entidade aninhada

        $ip = LookupService::summarizeRdap(json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/rdap/ip-arin.json'), true));
        self::assertSame('8.8.8.0 – 8.8.8.255', $ip['range']);
        self::assertSame(['8.8.8.0/24'], $ip['cidrs']);
    }

    public function testArpScanParserKeepsAllResponders(): void
    {
        $out = "10.77.0.10\t00:1b:21:aa:00:01\n10.77.0.10\t00:50:56:BB:00:02\t(DUP: 2)\n10.77.0.20\t3c:52:82:cc:00:03\n10.77.0.20\t3c:52:82:cc:00:03\nlixo\n";
        self::assertSame([
            '10.77.0.10' => ['00:1b:21:aa:00:01', '00:50:56:bb:00:02'],
            '10.77.0.20' => ['3c:52:82:cc:00:03'],
        ], ArpScanner::parse($out));
    }

    public function testMacHelpers(): void
    {
        self::assertSame('bc:24:11:12:34:56', MacVendors::normalize('bc24.1112.3456'));
        self::assertSame('00:09:0f:fe:00:01', MacVendors::normalize('00-09-0F-FE-00-01'));
        self::assertNull(MacVendors::normalize('00:09:0f'));
        self::assertTrue(MacVendors::isLocal('DAA119000001'));
        self::assertFalse(MacVendors::isLocal('00090FFE0001'));
        self::assertTrue(MacVendors::isMulticast('01005E000001'));

        $file = tempnam(sys_get_temp_dir(), 'oui');
        file_put_contents($file, "# teste\n001BC5 IEEE Registration Authority\n001BC50 Acme Sensores\n001BC5001 Acme Fino\n");
        $v = new MacVendors((string) $file);
        self::assertSame('Acme Fino', $v->lookup('00:1b:c5:00:1a:bb'));   // MA-S (36 bits)
        self::assertSame('Acme Sensores', $v->lookup('00:1b:c5:0f:00:00')); // MA-M (28 bits)
        self::assertSame('IEEE Registration Authority', $v->lookup('00:1b:c5:ff:00:00'));
        self::assertSame('MAC aleatório (privacidade)', $v->lookup('da:a1:19:00:00:01'));
        unlink((string) $file);
    }

    public function testNotifierPayloads(): void
    {
        $alerts = [
            ['id' => 1, 'kind' => 'ip_conflict', 'severity' => 'critical', 'title' => 'Conflito de IP: 10.0.0.5 <x>', 'ip' => '10.0.0.5', 'mac' => null, 'detail' => '{"macs":[]}', 'created_at' => '2026-11-26T10:00:00-03:00'],
            ['id' => 2, 'kind' => 'new_device', 'severity' => 'warning', 'title' => 'Dispositivo novo', 'ip' => '10.0.0.9', 'mac' => 'aa:bb:cc:dd:ee:ff', 'detail' => '{}', 'created_at' => '2026-11-26T10:00:00-03:00'],
        ];
        [$url, $tg] = Notifier::request('telegram', '123:abc', '-100', 'Matriz', $alerts, 'https://inv/app/tools/monitor');
        self::assertSame('https://api.telegram.org/bot123:abc/sendMessage', $url);
        self::assertSame('HTML', $tg['parse_mode']);
        self::assertStringContainsString('&lt;x&gt;', $tg['text']); // escapado
        self::assertStringContainsString('🔴', $tg['text']);

        [, $teams] = Notifier::request('teams', 'https://t', null, 'Matriz', $alerts, '');
        self::assertSame('AdaptiveCard', $teams['attachments'][0]['content']['type']);
        self::assertSame([], $teams['attachments'][0]['content']['actions']);

        [, $hook] = Notifier::request('webhook', 'https://h', null, 'Matriz', $alerts, 'https://inv/app/tools/monitor');
        self::assertSame('network_alerts', $hook['event']);
        self::assertSame(['macs' => []], $hook['alerts'][0]['detail']);
        self::assertSame('GLPI Bianchi — Matriz: 2 alertas', $hook['title']);

        $many = array_fill(0, 20, $alerts[1]);
        [, $slack] = Notifier::request('slack', 'https://s', null, 'X', $many, '');
        self::assertStringContainsString('… e mais 5', $slack['text']);
    }

    public function testChannelMasking(): void
    {
        self::assertSame('bot 123456789:••••', ChannelService::mask('telegram', '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw1'));
        $m = ChannelService::mask('teams', 'https://prod-00.westus.logic.azure.com/workflows/abc123def456ghi/triggers/manual/paths/invoke?sig=SECRET');
        self::assertStringNotContainsString('SECRET', $m);
        self::assertStringStartsWith('https://prod-00.westus.logic.azure.com/', $m);
    }

    public function testNextRun(): void
    {
        $svc = (new \ReflectionClass(ScheduleService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ScheduleService::class, 'timezone'))->setValue($svc, 'America/Sao_Paulo');
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $from = new \DateTimeImmutable('2026-11-26 19:10:30', $tz); // quinta-feira
        $fmt = static fn (\DateTimeImmutable $d): string => $d->setTimezone($tz)->format('Y-m-d H:i');

        self::assertSame('2026-11-27 02:30', $fmt($svc->nextRun('daily', null, '02:30', [1, 2, 3, 4, 5, 6, 7], $from)));
        self::assertSame('2026-11-26 20:00', $fmt($svc->nextRun('daily', null, '20:00', [1, 2, 3, 4, 5, 6, 7], $from)));
        self::assertSame('2026-11-30 07:00', $fmt($svc->nextRun('daily', null, '07:00', [1], $from)));
        self::assertSame('2026-12-03 19:10', $fmt($svc->nextRun('daily', null, '19:10', [4], $from)));
        self::assertSame('2026-11-27 01:10', $fmt($svc->nextRun('interval', 6, null, [], $from)));
    }
}
