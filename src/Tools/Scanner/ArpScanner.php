<?php

declare(strict_types=1);

namespace App\Tools\Scanner;

use App\Tools\Net\Command;

/**
 * Detector de conflito de IP (ADR-0013): `arp-scan` no segmento L2 do próprio servidor.
 *
 * Diferente do nmap (que guarda uma resposta por IP), o arp-scan registra **todas** as respostas
 * ARP: dois MACs respondendo pelo mesmo IP = conflito real (ou proxy-ARP/VRRP — ver a doc).
 * Só funciona em redes diretamente conectadas a uma interface do CT e exige CAP_NET_RAW
 * (o bianchi-scan-worker tem).
 */
final class ArpScanner
{
    public function available(): bool
    {
        return Command::exists('arp-scan');
    }

    /** Interface do servidor cuja rede contém o alvo (ex.: eth0 para 192.168.110.0/24), ou null. */
    public function interfaceFor(ScanTarget $target): ?string
    {
        $r = Command::run(['ip', '-j', '-4', 'addr', 'show'], 5);
        $ifaces = json_decode($r['stdout'], true);
        $best = null;
        $bestLen = -1;
        foreach (\is_array($ifaces) ? $ifaces : [] as $if) {
            foreach ($if['addr_info'] ?? [] as $a) {
                $len = (int) ($a['prefixlen'] ?? 32);
                $local = ip2long((string) ($a['local'] ?? ''));
                if (false === $local || 'lo' === ($if['ifname'] ?? '') || $len > $target->prefix || $len < 8) {
                    continue;
                }
                $mask = (0xFFFFFFFF << (32 - $len)) & 0xFFFFFFFF;
                if (($local & $mask) === ((int) ip2long($target->network) & $mask) && $len > $bestLen) {
                    $best = (string) $if['ifname'];
                    $bestLen = $len;
                }
            }
        }

        return $best;
    }

    /**
     * @return array<string, list<string>> IP → MACs que responderam (normalizados, sem repetição)
     */
    public function scan(string $interface, ScanTarget $target): array
    {
        if (1 !== preg_match('/^[A-Za-z0-9_.@:-]{1,15}$/', $interface)) {
            throw new \InvalidArgumentException('Interface inválida.');
        }
        $r = Command::run(['arp-scan', '-I', $interface, '-x', '-q', '-r', '2', '-t', '300', $target->cidr()], 30 + $target->addresses() / 50);
        if (0 !== $r['exit'] && '' === trim($r['stdout'])) {
            throw new \RuntimeException('arp-scan: '.mb_substr($r['stderr'] ?: 'falhou (exit '.$r['exit'].')', 0, 300));
        }

        return self::parse($r['stdout']);
    }

    /** @return array<string, list<string>> */
    public static function parse(string $out): array
    {
        $map = [];
        foreach (explode("\n", $out) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (!\is_array($f) || \count($f) < 2 || false === filter_var($f[0], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                continue;
            }
            $mac = MacVendors::normalize($f[1]);
            if (null !== $mac && !\in_array($mac, $map[$f[0]] ?? [], true)) {
                $map[$f[0]][] = $mac;
            }
        }

        return $map;
    }
}
