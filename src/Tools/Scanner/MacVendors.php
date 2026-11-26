<?php

declare(strict_types=1);

namespace App\Tools\Scanner;

/**
 * Fabricante pelo prefixo OUI do MAC, usando a base que já vem com o nmap
 * (/usr/share/nmap/nmap-mac-prefixes) — para MACs vindos do SNMP/NetBIOS/inventário.
 */
final class MacVendors
{
    /** @var array<string, string>|null */
    private ?array $map = null;

    public function __construct(private readonly string $file = '/usr/share/nmap/nmap-mac-prefixes')
    {
    }

    public function lookup(?string $mac): ?string
    {
        if (null === $mac) {
            return null;
        }
        $hex = strtoupper((string) preg_replace('/[^0-9a-f]/i', '', $mac));
        if (\strlen($hex) < 6) {
            return null;
        }
        // MAC aleatório (bit "localmente administrado"): celulares com privacidade de Wi-Fi
        if (0 !== (hexdec($hex[1]) & 0x2)) {
            return 'MAC aleatório (privacidade)';
        }
        $this->map ??= $this->load();

        return $this->map[substr($hex, 0, 6)] ?? null;
    }

    /** @return array<string, string> */
    private function load(): array
    {
        $map = [];
        $fh = @fopen($this->file, 'r');
        if (false === $fh) {
            return $map;
        }
        while (false !== ($line = fgets($fh))) {
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $hex = substr($line, 0, 6);
            if (6 === \strlen($hex) && ctype_xdigit($hex) && ' ' === ($line[6] ?? '')) {
                $map[strtoupper($hex)] = trim(substr($line, 7));
            }
        }
        fclose($fh);

        return $map;
    }
}
