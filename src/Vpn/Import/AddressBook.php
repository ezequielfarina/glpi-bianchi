<?php

declare(strict_types=1);

namespace App\Vpn\Import;

/** Objetos e grupos de endereço do FortiGate → lista de CIDRs (grupos aninhados, com proteção contra ciclo). */
final class AddressBook
{
    /**
     * @param array<string, mixed> $addr
     * @param array<string, mixed> $grp
     * @param array<string, mixed> $addr6
     * @param array<string, mixed> $grp6
     */
    public function __construct(private readonly array $addr, private readonly array $grp, private readonly array $addr6 = [], private readonly array $grp6 = [])
    {
    }

    /** @return list<string> */
    public function resolve(string $name, int $depth = 0): array
    {
        if ('' === $name || $depth > 8) {
            return [];
        }
        if ('all' === strtolower($name)) {
            return ['0.0.0.0/0'];
        }
        $a = $this->addr[$name] ?? $this->addr6[$name] ?? null;
        if (\is_array($a)) {
            $s = $a['set'] ?? [];
            $type = $s['type'][0] ?? (isset($s['ip6']) ? 'ipprefix' : 'ipmask');

            return match ($type) {
                'iprange' => [($s['start-ip'][0] ?? '?').'-'.($s['end-ip'][0] ?? '?')],
                'fqdn' => ['fqdn:'.($s['fqdn'][0] ?? $name)],
                'geography' => ['país:'.($s['country'][0] ?? '?')],
                default => [Cidr::from(implode(' ', $s['subnet'] ?? $s['ip6'] ?? ['0.0.0.0', '0.0.0.0']))],
            };
        }
        $g = $this->grp[$name] ?? $this->grp6[$name] ?? null;
        if (\is_array($g)) {
            $out = [];
            foreach ($g['set']['member'] ?? [] as $m) {
                $out = [...$out, ...$this->resolve((string) $m, $depth + 1)];
            }

            return array_values(array_unique($out));
        }

        return ['objeto:'.$name]; // não veio no texto colado: mantém o nome
    }
}
