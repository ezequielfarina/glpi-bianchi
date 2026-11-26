<?php

declare(strict_types=1);

namespace App\Tools\Scanner;

/**
 * Alvo de varredura validado (IPv4). Regras (ADR-0012):
 * - só redes privadas: RFC 1918 (10/8, 172.16/12, 192.168/16) e CGNAT (100.64/10) —
 *   o scanner não vira ferramenta de varredura da internet;
 * - tamanho máximo por perfil (descoberta /20 = 4.096 IPs; padrão /22; completo /24).
 */
final readonly class ScanTarget
{
    public const array MAX_PREFIX = [ScanProfile::DISCOVERY => 20, ScanProfile::STANDARD => 22, ScanProfile::FULL => 24];

    private const array PRIVATE = [['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['100.64.0.0', 10]];

    private function __construct(
        public string $network,
        public int $prefix,
    ) {
    }

    /** @throws \InvalidArgumentException com mensagem para o usuário */
    public static function parse(string $input, string $profile): self
    {
        $input = trim($input);
        if (1 !== preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#', $input, $m)) {
            throw new \InvalidArgumentException('Informe uma rede IPv4 em CIDR (ex.: 192.168.10.0/24) ou um IP.');
        }
        $long = ip2long($m[1]);
        $prefix = isset($m[2]) ? (int) $m[2] : 32;
        if (false === $long || $prefix > 32) {
            throw new \InvalidArgumentException('Endereço ou prefixo inválido.');
        }
        $mask = 0 === $prefix ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $network = $long & $mask;
        if (!self::isPrivate($network, $prefix)) {
            throw new \InvalidArgumentException('Por segurança, só redes privadas podem ser escaneadas (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10).');
        }
        $max = self::MAX_PREFIX[$profile] ?? 24;
        if ($prefix < $max) {
            throw new \InvalidArgumentException(\sprintf(
                'Para este perfil o máximo é /%d (%s endereços). %s',
                $max, number_format(2 ** (32 - $max), 0, ',', '.'),
                ScanProfile::DISCOVERY === $profile ? 'Divida a rede em partes menores.' : 'Divida a rede ou use a "Descoberta rápida" (até /20).',
            ));
        }

        return new self((string) long2ip($network), $prefix);
    }

    public function cidr(): string
    {
        return $this->network.'/'.$this->prefix;
    }

    public function addresses(): int
    {
        return 2 ** (32 - $this->prefix);
    }

    public function contains(string $ip): bool
    {
        $l = ip2long($ip);
        if (false === $l) {
            return false;
        }
        $mask = 0 === $this->prefix ? 0 : (0xFFFFFFFF << (32 - $this->prefix)) & 0xFFFFFFFF;

        return ($l & $mask) === ip2long($this->network);
    }

    private static function isPrivate(int $network, int $prefix): bool
    {
        foreach (self::PRIVATE as [$base, $bits]) {
            $m = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
            if ($prefix >= $bits && ($network & $m) === ip2long($base)) {
                return true;
            }
        }

        return false;
    }
}
