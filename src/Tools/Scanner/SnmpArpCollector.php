<?php

declare(strict_types=1);

namespace App\Tools\Scanner;

/**
 * Lê a tabela ARP do gateway/roteador via SNMP v2c (FortiGate, MikroTik, switches L3):
 * é o que dá o **MAC de hosts de outras VLANs**, que o nmap não enxerga fora do segmento
 * do servidor — e revela hosts que não respondem a nada (firewall), mas falaram com o gateway.
 *
 * OIDs: ipNetToMediaPhysAddress (1.3.6.1.2.1.4.22.1.2) e, se vazio,
 * ipNetToPhysicalPhysAddress (1.3.6.1.2.1.4.35.1.4). Requer a extensão php-snmp.
 */
class SnmpArpCollector
{
    public function available(): bool
    {
        return \extension_loaded('snmp');
    }

    /**
     * @return array<string, string> ip => mac (aa:bb:cc:dd:ee:ff), só IPs dentro do alvo
     */
    public function collect(string $gateway, #[\SensitiveParameter] string $community, ScanTarget $target): array
    {
        if (!$this->available()) {
            throw new \RuntimeException('Extensão PHP snmp não instalada (apt install php-snmp).');
        }
        snmp_set_quick_print(true);
        snmp_set_oid_output_format(\SNMP_OID_OUTPUT_NUMERIC);
        snmp_set_valueretrieval(\SNMP_VALUE_PLAIN);

        $out = [];
        foreach (['1.3.6.1.2.1.4.22.1.2', '1.3.6.1.2.1.4.35.1.4'] as $oid) {
            $rows = @snmp2_real_walk($gateway, $community, $oid, 2_000_000, 1);
            if (!\is_array($rows) || [] === $rows) {
                continue;
            }
            foreach ($rows as $k => $v) {
                // sufixo: ...<ifIndex>.a.b.c.d  (ou ...<ifIndex>.1.4.a.b.c.d na tabela nova)
                if (1 !== preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/', (string) $k, $m)) {
                    continue;
                }
                $mac = self::mac((string) $v);
                if (null !== $mac && $target->contains($m[1])) {
                    $out[$m[1]] = $mac;
                }
            }
            if ([] !== $out) {
                break;
            }
        }
        if ([] === $out && false === @snmp2_get($gateway, $community, '1.3.6.1.2.1.1.1.0', 2_000_000, 1)) {
            throw new \RuntimeException(\sprintf('Sem resposta SNMP de %s (comunidade, ACL ou UDP 161 bloqueado?).', $gateway));
        }

        return $out;
    }

    private static function mac(string $raw): ?string
    {
        $hex = 6 === \strlen($raw) ? bin2hex($raw) : strtolower((string) preg_replace('/[^0-9a-f]/i', '', $raw));
        if (12 !== \strlen($hex) || '000000000000' === $hex || 'ffffffffffff' === $hex) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }
}
