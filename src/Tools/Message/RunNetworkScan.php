<?php

declare(strict_types=1);

namespace App\Tools\Message;

/**
 * Pede ao worker do scanner (transporte "scan") para executar a varredura.
 * A comunidade SNMP viaja **cifrada** (SecretBox) e não é gravada no banco.
 */
final readonly class RunNetworkScan
{
    public function __construct(
        public int $scanId,
        public ?string $snmpGateway = null,
        public ?string $encryptedCommunity = null,
    ) {
    }
}
