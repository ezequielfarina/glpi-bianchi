<?php

declare(strict_types=1);

namespace App\Vpn\Service;

/**
 * Confere se os dois lados de um túnel IPsec negociam (ADR-0022) — o "por que não sobe" mais comum:
 * versão IKE, modo, autenticação, interseção de propostas e grupos DH (fase 1 e 2), PFS, e seletores da fase 2
 * espelhados (local de um = remoto do outro). Campos omitidos (`show` sem full-configuration) usam o padrão do
 * FortiOS 7.x e a mensagem avisa que é padrão.
 */
final class TunnelCompat
{
    public const array P1_DEFAULT = ['proposal' => 'aes128-sha256 aes256-sha256 aes128-sha1 aes256-sha1', 'dhgrp' => '14 5', 'keylife' => 86400];
    public const array P2_DEFAULT = ['proposal' => 'aes128-sha1 aes256-sha1 aes128-sha256 aes256-sha256 aes128gcm aes256gcm chacha20poly1305',
        'dhgrp' => '14 5', 'pfs' => true, 'keylife' => 43200];

    /**
     * @param array<string, mixed>       $a  túnel deste lado
     * @param array<string, mixed>       $b  túnel do outro lado
     * @param list<array<string, mixed>> $pa fases 2 deste lado
     * @param list<array<string, mixed>> $pb fases 2 do outro lado
     *
     * @return list<array{level: string, msg: string}>
     */
    public static function check(array $a, array $b, array $pa, array $pb): array
    {
        $out = [];
        $add = static function (string $level, string $msg) use (&$out): void {
            $out[] = ['level' => $level, 'msg' => $msg];
        };

        if ((int) $a['ike_version'] !== (int) $b['ike_version']) {
            $add('error', \sprintf('Versão IKE diferente: IKEv%d × IKEv%d.', $a['ike_version'], $b['ike_version']));
        } else {
            $add('ok', 'IKEv'.$a['ike_version'].' dos dois lados.');
        }
        if (1 === (int) $a['ike_version'] && ($a['ike_mode'] ?? 'main') !== ($b['ike_mode'] ?? 'main')) {
            $add('error', \sprintf('Modo IKEv1 diferente: %s × %s.', $a['ike_mode'] ?? 'main', $b['ike_mode'] ?? 'main'));
        }
        if (($a['auth_method'] ?? 'psk') !== ($b['auth_method'] ?? 'psk')) {
            $add('error', \sprintf('Autenticação diferente: %s × %s.', $a['auth_method'] ?? 'psk', $b['auth_method'] ?? 'psk'));
        }
        if (null !== $a['remote_gw'] && null !== $b['local_ip'] && $a['remote_gw'] !== $b['local_ip']) {
            $add('warn', \sprintf('Este lado aponta para %s, mas o outro sai por %s.', $a['remote_gw'], $b['local_ip']));
        }

        self::match($add, 'Fase 1 — proposta', $a['p1_proposal'] ?? null, $b['p1_proposal'] ?? null, self::P1_DEFAULT['proposal']);
        self::match($add, 'Fase 1 — grupo DH', $a['p1_dhgroups'] ?? null, $b['p1_dhgroups'] ?? null, self::P1_DEFAULT['dhgrp']);
        $ka = $a['p1_keylife'] ?? self::P1_DEFAULT['keylife'];
        $kb = $b['p1_keylife'] ?? self::P1_DEFAULT['keylife'];
        if ((int) $ka !== (int) $kb) {
            $add('info', \sprintf('Keylife da fase 1 diferente (%ds × %ds): sobe, mas renegocia em tempos diferentes.', $ka, $kb));
        }

        foreach ($pa as $x) {
            $mirror = null;
            foreach ($pb as $y) {
                if (self::same($x['src'], $y['dst']) && self::same($x['dst'], $y['src'])) {
                    $mirror = $y;
                    break;
                }
            }
            $label = 'Fase 2 '.$x['name'];
            if (null === $mirror) {
                $add('error', \sprintf(
                    '%s: seletores sem espelho do outro lado (local %s → remoto %s). O outro lado precisa ter local %s e remoto %s.',
                    $label,
                    implode(', ', $x['src']),
                    implode(', ', $x['dst']),
                    implode(', ', $x['dst']),
                    implode(', ', $x['src'])
                ));
                continue;
            }
            $add('ok', \sprintf('%s ↔ %s: seletores espelhados.', $label, $mirror['name']));
            self::match($add, $label.' — proposta', $x['proposal'] ?? null, $mirror['proposal'] ?? null, self::P2_DEFAULT['proposal']);
            $pfsA = $x['pfs'] ?? self::P2_DEFAULT['pfs'];
            $pfsB = $mirror['pfs'] ?? self::P2_DEFAULT['pfs'];
            if ($pfsA !== $pfsB) {
                $add('error', \sprintf('%s: PFS %s × %s.', $label, $pfsA ? 'ligado' : 'desligado', $pfsB ? 'ligado' : 'desligado'));
            } elseif ($pfsA) {
                self::match($add, $label.' — grupo DH (PFS)', $x['dhgroups'] ?? null, $mirror['dhgroups'] ?? null, self::P2_DEFAULT['dhgrp']);
            }
            $la = $x['keylife_seconds'] ?? self::P2_DEFAULT['keylife'];
            $lb = $mirror['keylife_seconds'] ?? self::P2_DEFAULT['keylife'];
            if ((int) $la !== (int) $lb) {
                $add('info', \sprintf('%s: keylife diferente (%ds × %ds).', $label, $la, $lb));
            }
        }

        return $out;
    }

    /** Resumo: error > warn > ok. */
    public static function verdict(array $checks): string
    {
        $levels = array_column($checks, 'level');

        return \in_array('error', $levels, true) ? 'error' : (\in_array('warn', $levels, true) ? 'warn' : 'ok');
    }

    /** @param callable(string, string): void $add */
    private static function match(callable $add, string $what, ?string $a, ?string $b, string $default): void
    {
        $sa = preg_split('/\s+/', trim($a ?? $default)) ?: [];
        $sb = preg_split('/\s+/', trim($b ?? $default)) ?: [];
        $common = array_values(array_intersect($sa, $sb));
        $note = null === $a || null === $b ? ' (um lado no padrão do FortiOS)' : '';
        if ([] === $common) {
            $add('error', \sprintf('%s sem item em comum: [%s] × [%s]%s.', $what, implode(' ', $sa), implode(' ', $sb), $note));
        } else {
            $add('ok', \sprintf('%s: negocia %s%s.', $what, $common[0], $note));
        }
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function same(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
