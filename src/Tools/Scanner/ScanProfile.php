<?php

declare(strict_types=1);

namespace App\Tools\Scanner;

/**
 * Perfis de varredura → argumentos do nmap. Os argumentos são montados aqui, nunca
 * vêm do usuário (sem injeção de opções). Com CAP_NET_RAW (worker do scanner) usa
 * SYN scan, detecção de SO e ARP; sem ela, cai para TCP connect (menos informação).
 */
final class ScanProfile
{
    public const string DISCOVERY = 'discovery';
    public const string STANDARD = 'standard';
    public const string FULL = 'full';
    public const array ALL = [self::DISCOVERY, self::STANDARD, self::FULL];

    /** Portas usadas para "pingar" hosts que bloqueiam ICMP (Windows com firewall, impressoras...). */
    private const string PING_TCP = '21,22,23,25,53,80,135,139,443,445,515,554,631,3389,5060,8080,8291,8443,9100';

    private const string FULL_PORTS = 'T:1-1024,1433,1521,1723,1883,2049,3000,3306,3389,5000,5060,5061,5432,5900,5985,5986,6379,8000,8006,8008,8080,8081,8088,8291,8443,8728,8888,9000,9090,9100,9200,10000,27017,U:53,67,69,123,137,161,500,623,1900,5353';

    private const string SCRIPTS_STANDARD = 'nbstat,smb-os-discovery,http-title,http-server-header,ssl-cert,rdp-ntlm-info,snmp-sysdescr';
    private const string SCRIPTS_FULL = 'nbstat,smb-os-discovery,smb2-security-mode,http-title,http-server-header,http-generator,ssl-cert,rdp-ntlm-info,snmp-sysdescr,snmp-info,upnp-info,ntp-info,vnc-info,mysql-info,ms-sql-info,ssh2-enum-algos';

    public const array LABEL = [
        self::DISCOVERY => 'Descoberta rápida',
        self::STANDARD => 'Padrão',
        self::FULL => 'Completo',
    ];

    /** @return list<string> */
    public static function args(string $profile, bool $privileged, ?string $snmpCommunity): array
    {
        $common = ['-T4', '--max-retries', '2', '--stats-every', '2s', '-oX', '-', '--noninteractive'];
        $ping = $privileged
            ? ['-PR', '-PE', '-PP', '-PS'.self::PING_TCP, '-PA80,443', '-PU137,161']
            : ['-PS'.self::PING_TCP];
        $scriptArgs = null !== $snmpCommunity && '' !== $snmpCommunity
            ? ['--script-args', 'snmpcommunity='.$snmpCommunity]
            : [];

        $args = match ($profile) {
            self::DISCOVERY => [...$ping, '-sn', '--script', 'nbstat', '--host-timeout', '60s'],
            self::STANDARD => [
                ...$ping,
                $privileged ? '-sS' : '-sT', '--top-ports', '200', '-sV', '--version-light',
                ...($privileged ? ['-O', '--osscan-limit', '--max-os-tries', '1'] : []),
                '--script', self::SCRIPTS_STANDARD, ...$scriptArgs, '--host-timeout', '5m',
            ],
            self::FULL => [
                ...$ping,
                ...($privileged ? ['-sS', '-sU', '-p', self::FULL_PORTS] : ['-sT', '-p', preg_replace('/,U:.*$/', '', self::FULL_PORTS) ?? '']),
                '-sV', '--version-intensity', '7',
                ...($privileged ? ['-O', '--osscan-guess'] : []),
                '--script', self::SCRIPTS_FULL, ...$scriptArgs, '--host-timeout', '15m',
            ],
            default => throw new \InvalidArgumentException('Perfil inválido.'),
        };

        return [...($privileged ? ['--privileged'] : ['--unprivileged']), ...$args, ...$common];
    }
}
