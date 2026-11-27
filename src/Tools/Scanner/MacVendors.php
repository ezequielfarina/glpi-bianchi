<?php

declare(strict_types=1);

namespace App\Tools\Scanner;

/**
 * Fabricante pelo prefixo do MAC, usando a base que já vem com o nmap
 * (/usr/share/nmap/nmap-mac-prefixes): OUI de 24 bits (MA-L) e blocos de 28/36 bits (MA-M/MA-S),
 * com o prefixo mais longo vencendo.
 */
final class MacVendors
{
    /**
     * MACs fixos de adaptadores virtuais que se repetem em toda máquina que tem o software —
     * não indicam clonagem/conflito (ConflictService ignora; consulta de MAC sinaliza).
     */
    public const array SHARED_VIRTUAL = [
        '00:09:0f:fe:00:01' => 'FortiClient (adaptador virtual SSL-VPN)',
        '02:00:4c:4f:4f:50' => 'Npcap Loopback Adapter',
        '00:05:9a:3c:7a:00' => 'Cisco AnyConnect / Secure Client',
        '00:50:56:c0:00:01' => 'VMware Workstation (VMnet1, host-only)',
        '00:50:56:c0:00:08' => 'VMware Workstation (VMnet8, NAT)',
        '0a:00:27:00:00:00' => 'VirtualBox Host-Only',
        '00:ff:00:00:00:00' => 'Placeholder',
    ];

    /** OUIs de hipervisores (MAC de VM). */
    public const array HYPERVISOR = [
        '00:50:56' => 'VMware', '00:0c:29' => 'VMware', '00:05:69' => 'VMware',
        '00:15:5d' => 'Hyper-V', '52:54:00' => 'QEMU/KVM', '08:00:27' => 'VirtualBox',
        '00:16:3e' => 'Xen', 'bc:24:11' => 'Proxmox VE', '00:1c:42' => 'Parallels', '02:42:ac' => 'Docker',
    ];

    /** @var array<string, string>|null prefixo hex maiúsculo (6, 7 ou 9 dígitos) → fabricante */
    private ?array $map = null;

    public function __construct(private readonly string $file = '/usr/share/nmap/nmap-mac-prefixes')
    {
    }

    /** Fabricante, ou "MAC aleatório (privacidade)" quando o bit localmente administrado está ligado. */
    public function lookup(?string $mac): ?string
    {
        $hex = self::hex($mac);
        if (null === $hex) {
            return null;
        }
        if (self::isLocal($hex) && null === $this->vendor($hex)) {
            return 'MAC aleatório (privacidade)';
        }

        return $this->vendor($hex);
    }

    /** Só o registro IEEE (sem a heurística de MAC aleatório). */
    public function vendor(string $hex): ?string
    {
        $this->map ??= $this->load();
        foreach ([9, 7, 6] as $len) {
            if (isset($this->map[substr($hex, 0, $len)])) {
                return $this->map[substr($hex, 0, $len)];
            }
        }

        return null;
    }

    public function available(): bool
    {
        $this->map ??= $this->load();

        return [] !== $this->map;
    }

    /** 12 dígitos hex maiúsculos, ou null se não for um MAC (aceita aa:bb.., aa-bb.., aabb.ccdd.eeff, aabbccddeeff). */
    public static function hex(?string $mac): ?string
    {
        if (null === $mac) {
            return null;
        }
        $hex = strtoupper((string) preg_replace('/[\s:.\-]/', '', $mac));

        return 1 === preg_match('/^[0-9A-F]{12}$/', $hex) ? $hex : null;
    }

    /** aa:bb:cc:dd:ee:ff (formato usado no banco) */
    public static function normalize(?string $mac): ?string
    {
        $hex = self::hex($mac);

        return null === $hex ? null : strtolower(implode(':', str_split($hex, 2)));
    }

    public static function isLocal(string $hex): bool
    {
        return 0 !== (hexdec($hex[1]) & 0x2);
    }

    public static function isMulticast(string $hex): bool
    {
        return 0 !== (hexdec($hex[1]) & 0x1);
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
            $sp = strpos($line, ' ');
            if (false === $sp || !\in_array($sp, [6, 7, 9], true)) {
                continue;
            }
            $hex = strtoupper(substr($line, 0, $sp));
            if (ctype_xdigit($hex)) {
                $map[$hex] = trim(substr($line, $sp + 1));
            }
        }
        fclose($fh);

        return $map;
    }
}
