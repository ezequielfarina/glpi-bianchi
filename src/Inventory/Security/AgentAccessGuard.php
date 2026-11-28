<?php

declare(strict_types=1);

namespace App\Inventory\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proteção do endpoint público do GLPI Agent (auditoria 2026-11): sem ela, qualquer um que alcance
 * o servidor envia inventário falso e sobrescreve dados de um ativo existente (mesmo serial/UUID).
 *
 * - origem: só redes de AGENT_ALLOWED_NETWORKS (padrão: faixas privadas + loopback);
 * - credencial opcional: AGENT_BASIC_AUTH="usuario:senha" exige HTTP Basic — o GLPI Agent envia
 *   com as opções `user` e `password` (agent.cfg / instalador: /USER=... /PASSWORD=...);
 * - limite: AGENT_MAX_REQUESTS_PER_5MIN por IP (padrão 120) contra inundação de payloads.
 */
final readonly class AgentAccessGuard
{
    public function __construct(
        #[Autowire('%env(AGENT_ALLOWED_NETWORKS)%')]
        private string $allowedNetworks,
        #[Autowire('%env(AGENT_BASIC_AUTH)%')]
        #[\SensitiveParameter]
        private string $basicAuth,
        #[Autowire('%env(int:AGENT_MAX_REQUESTS_PER_5MIN)%')]
        private int $maxPer5Min,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** @return array{int, string}|null [status HTTP, motivo] quando recusado */
    public function deny(Request $request): ?array
    {
        $ip = (string) $request->getClientIp();
        $networks = array_values(array_filter(array_map('trim', explode(',', $this->allowedNetworks))));
        if ([] !== $networks && !IpUtils::checkIp($ip, $networks)) {
            return [403, 'Origin network not allowed.'];
        }
        if ('' !== $this->basicAuth) {
            $user = $request->headers->get('PHP_AUTH_USER');
            if (null === $user || !hash_equals($this->basicAuth, $user.':'.$request->headers->get('PHP_AUTH_PW', ''))) {
                return [401, 'Authentication required.'];
            }
        }
        if ($this->maxPer5Min > 0) {
            $item = $this->cache->getItem('agent_rate_'.hash('xxh128', $ip.'|'.intdiv(time(), 300)));
            $count = (\is_int($item->get()) ? $item->get() : 0) + 1;
            $this->cache->save($item->set($count)->expiresAfter(330));
            if ($count > $this->maxPer5Min) {
                return [429, 'Too many requests.'];
            }
        }

        return null;
    }
}
