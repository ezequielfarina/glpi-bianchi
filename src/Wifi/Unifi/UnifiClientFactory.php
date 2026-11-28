<?php

declare(strict_types=1);

namespace App\Wifi\Unifi;

use App\Shared\Security\SecretBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Abre uma sessão com o controller a partir do registro do banco (segredo decifrado só em memória). */
final readonly class UnifiClientFactory
{
    public function __construct(
        #[Autowire(service: 'http_client')]
        private HttpClientInterface $http,
        private SecretBox $box,
    ) {
    }

    /** @param array<string, mixed> $row linha de unifi_controller */
    public function connect(array $row): UnifiConnection
    {
        $secret = null === ($row['secret'] ?? null) ? null : $this->box->decrypt((string) $row['secret'], self::context((int) $row['id']));

        return $this->fromConfig([
            'base_url' => (string) $row['base_url'], 'auth_mode' => (string) $row['auth_mode'], 'username' => self::nullable($row['username'] ?? null),
            'secret' => $secret, 'cloud_host_id' => self::nullable($row['cloud_host_id'] ?? null), 'verify_tls' => (bool) $row['verify_tls'], 'tls_pin' => self::nullable($row['tls_pin'] ?? null),
        ]);
    }

    /** @param array{base_url: string, auth_mode: string, username: ?string, secret: ?string, cloud_host_id: ?string, verify_tls: bool, tls_pin: ?string} $cfg */
    public function fromConfig(array $cfg): UnifiConnection
    {
        if ('cloud' !== $cfg['auth_mode']) {
            NetworkGuard::assertInternal($cfg['base_url']);
        }

        return new UnifiConnection($this->http, $cfg);
    }

    public static function context(int $controllerId): string
    {
        return 'unifi.secret:'.$controllerId;
    }

    private static function nullable(mixed $v): ?string
    {
        return \is_string($v) && '' !== $v ? $v : null;
    }
}
