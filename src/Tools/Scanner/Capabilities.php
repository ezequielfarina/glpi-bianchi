<?php

declare(strict_types=1);

namespace App\Tools\Scanner;

/**
 * O processo atual pode abrir sockets raw? (root, ou CAP_NET_RAW ambiente — o worker
 * `bianchi-scan-worker` recebe só NET_RAW/NET_ADMIN via systemd, sem virar root.)
 */
final class Capabilities
{
    public static function canRawSocket(): bool
    {
        if (\function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            return true;
        }
        $status = @file_get_contents('/proc/self/status');
        if (false === $status || 1 !== preg_match('/^CapEff:\s*([0-9a-f]+)/mi', $status, $m)) {
            return false;
        }
        $eff = hexdec(substr($m[1], -8));

        return 0 !== ((int) $eff & (1 << 13)); // CAP_NET_RAW = 13
    }
}
