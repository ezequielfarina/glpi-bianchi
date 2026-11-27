<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ipam;

use App\Ipam\Service\IpNet;
use PHPUnit\Framework\TestCase;

final class IpNetTest extends TestCase
{
    public function testNormalizesAndUsableRange(): void
    {
        $p = IpNet::parse('192.168.10.77/24');
        self::assertNotNull($p);
        self::assertSame('192.168.10.0/24', IpNet::cidr($p['bin'], $p['len']));
        [$first, $last] = IpNet::usable(IpNet::network($p['bin'], 24), 24, false);
        self::assertSame(['192.168.10.1', '192.168.10.254'], [IpNet::text($first), IpNet::text($last)]);
        [$first, $last] = IpNet::usable(IpNet::network($p['bin'], 24), 24, true);
        self::assertSame(['192.168.10.0', '192.168.10.255'], [IpNet::text($first), IpNet::text($last)]);
        self::assertNull(IpNet::parse('10.0.0.300'));
        self::assertNull(IpNet::parse('10.0.0.0/33'));
    }

    public function testFirstFreeSkipsUsedIntervals(): void
    {
        $b = static fn (string $ip): string => (string) inet_pton($ip);
        $free = IpNet::firstFree($b('10.0.0.1'), $b('10.0.0.254'), [[$b('10.0.0.2'), $b('10.0.0.50')], [$b('10.0.0.1'), $b('10.0.0.1')], [$b('10.0.0.52'), $b('10.0.0.52')]]);
        self::assertSame('10.0.0.51', null === $free ? null : IpNet::text($free));
        self::assertNull(IpNet::firstFree($b('10.0.0.1'), $b('10.0.0.2'), [[$b('10.0.0.1'), $b('10.0.0.2')]]));
        $v6 = IpNet::parse('2001:db8::/64');
        self::assertNotNull($v6);
        [$first] = IpNet::usable($v6['bin'], 64, false);
        self::assertSame('2001:db8::1', IpNet::text($first));
        self::assertSame('::1:0:0', IpNet::text(IpNet::inc($b('::ffff:ffff'))));
    }

    public function testFreeCidrsBetweenChildren(): void
    {
        $used = [[(int) ip2long('10.0.1.0'), (int) ip2long('10.0.1.255')], [(int) ip2long('10.0.8.0'), (int) ip2long('10.0.15.255')]];
        self::assertSame(
            ['10.0.0.0/24', '10.0.2.0/23', '10.0.4.0/22', '10.0.16.0/20', '10.0.32.0/19', '10.0.64.0/18', '10.0.128.0/17'],
            IpNet::freeCidrs4((int) ip2long('10.0.0.0'), (int) ip2long('10.0.255.255'), $used),
        );
    }
}
