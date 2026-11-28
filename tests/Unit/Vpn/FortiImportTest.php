<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vpn;

use App\Vpn\Import\Cidr;
use App\Vpn\Import\FortiConfigParser;
use App\Vpn\Import\FortiVpnExtractor;
use App\Vpn\Service\TunnelCompat;
use PHPUnit\Framework\TestCase;

final class FortiImportTest extends TestCase
{
    private const string DIR = __DIR__.'/../../Fixtures/fortigate/';

    /** @return array<string, mixed> */
    private function load(string $file): array
    {
        return (new FortiVpnExtractor())->extract((new FortiConfigParser())->parse((string) file_get_contents(self::DIR.$file)));
    }

    /** @return array<string, array<string, mixed>> */
    private function byName(array $r): array
    {
        return array_column($r['tunnels'], null, 'name');
    }

    public function testBackupOfMatriz(): void
    {
        $r = $this->load('matriz.conf');
        self::assertSame(['FGT-MATRIZ', 'FG100F', '7.2.8'], [$r['hostname'], $r['model'], $r['version']]);
        $t = $this->byName($r);
        self::assertSame(['VPN-CVL', 'VPN-BANCO', 'FORTICLIENT'], array_keys($t));

        $cvl = $t['VPN-CVL'];
        self::assertSame('200.150.10.2', $cvl['local_ip'], 'IP da interface WAN');
        self::assertSame('187.45.60.10', $cvl['remote_gw']);
        self::assertSame(2, $cvl['ike_version']);
        self::assertSame('aes256-sha256 aes128-sha256', $cvl['p1_proposal']);
        self::assertSame('169.254.10.1/32', $cvl['tunnel_ip']);
        self::assertSame(['192.168.30.0/24', '192.168.31.0/24'], $cvl['routes'], 'rota blackhole não conta');
        self::assertSame('VPN-OVERLAY', $cvl['sdwan_zone']);
        self::assertSame('Túnel Matriz <-> Cascavel', $cvl['description']);
        self::assertSame(['192.168.10.0/24', '192.168.20.0/24'], $cvl['phase2'][0]['src'], 'grupo de endereços resolvido');
        self::assertSame(['192.168.30.0/24'], $cvl['phase2'][0]['dst']);

        self::assertSame(['main', 28800, 'disable'], [$t['VPN-BANCO']['ike_mode'], $t['VPN-BANCO']['p1_keylife'], $t['VPN-BANCO']['nat_traversal']]);
        self::assertFalse($t['VPN-BANCO']['phase2'][0]['pfs']);
        self::assertSame(['192.168.20.10/32'], $t['VPN-BANCO']['phase2'][0]['src']);
        self::assertSame('dialup', $t['FORTICLIENT']['topology']);
        self::assertNull($t['FORTICLIENT']['remote_gw']);
    }

    public function testSecretsNeverLeaveTheExtractor(): void
    {
        foreach (['matriz.conf', 'cascavel-show.txt', 'multivdom.conf'] as $f) {
            $r = $this->load($f);
            $json = (string) json_encode($r);
            foreach (['DONOTSTORE', 'SIDESECRET', 'FORTICLIENTSECRET', 'CERTPASS', 'ENC ', 'BEGIN', 'psksecret'] as $bad) {
                self::assertStringNotContainsStringIgnoringCase($bad, $json, "$f vazou $bad");
            }
            self::assertGreaterThan(0, $r['secrets_removed']);
        }
    }

    public function testShowOutputWithPromptAndMultiVdom(): void
    {
        $cvl = $this->load('cascavel-show.txt');
        self::assertSame('FGT-CVL', $cvl['hostname'], 'hostname tirado do prompt');
        self::assertSame('187.45.60.10', $cvl['tunnels'][0]['local_ip'], 'local-gw');

        $dc = $this->load('multivdom.conf');
        self::assertSame('FGT-DC', $dc['hostname']);
        self::assertSame(['DMZ', 'spoke', '10.99.0.2'], [$dc['tunnels'][0]['vdom'], $dc['tunnels'][0]['topology'], $dc['tunnels'][0]['local_ip']]);
    }

    public function testCompatibilityFindsRealMismatches(): void
    {
        $a = $this->byName($this->load('matriz.conf'))['VPN-CVL'];
        $b = $this->load('cascavel-show.txt')['tunnels'][0];
        $checks = TunnelCompat::check($a, $b, $a['phase2'], $b['phase2']);
        $msgs = implode("\n", array_column($checks, 'msg'));
        self::assertSame('error', TunnelCompat::verdict($checks));
        self::assertStringContainsString('IKEv2 dos dois lados', $msgs);
        self::assertStringContainsString('Fase 1 — proposta: negocia aes256-sha256', $msgs);
        self::assertStringContainsString('Fase 1 — grupo DH: negocia 14', $msgs);
        self::assertStringContainsString('seletores sem espelho', $msgs, 'Matriz manda 10+20, Cascavel só espera 10');

        // seletores corrigidos: aparece o DH da fase 2 diferente (14 × 5)
        $b['phase2'][0]['dst'] = ['192.168.10.0/24', '192.168.20.0/24'];
        $checks = TunnelCompat::check($a, $b, $a['phase2'], $b['phase2']);
        $msgs = implode("\n", array_column($checks, 'msg'));
        self::assertStringContainsString('seletores espelhados', $msgs);
        self::assertStringContainsString('grupo DH (PFS) sem item em comum: [14] × [5]', $msgs);
    }

    public function testParserEdgeCases(): void
    {
        $tree = (new FortiConfigParser())->parse("config firewall address\n    edit \"A \\\"x\\\"\"\n        set comment \"linha1\nlinha2\"\n    next\nend\n")['tree'];
        $edit = $tree['config']['firewall address']['edit'];
        self::assertArrayHasKey('A "x"', $edit);
        self::assertSame(["linha1\nlinha2"], $edit['A "x"']['set']['comment']);
        self::assertSame('10.0.0.0/8', Cidr::from('10.0.0.0 255.0.0.0'));
        self::assertSame('10.1.1.1/32', Cidr::from('10.1.1.1'));
        self::assertSame('2001:db8::/32', Cidr::from('2001:DB8::/32'));
    }
}
