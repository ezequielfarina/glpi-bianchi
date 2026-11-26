<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Autenticação da API no MVP: um único token estático (env API_TOKEN).
 *
 * Comparação em tempo constante. Token vazio desabilita a API por completo
 * (nenhum token é aceito), evitando API aberta por esquecimento de configuração.
 * Fase 2: substituir por OIDC (tokens JWT validados contra o IdP).
 */
final readonly class StaticApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        #[\SensitiveParameter]
        private string $apiToken,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        if ('' === $this->apiToken || !hash_equals($this->apiToken, $accessToken)) {
            throw new BadCredentialsException('Invalid API token.');
        }

        return new UserBadge('api');
    }
}
