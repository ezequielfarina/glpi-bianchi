<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Identity\Security\PasswordHasher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Login real no painel para os testes de /api/ui: cria a conta direto no banco e
 * faz POST /api/ui/auth/login — o cookie de sessão fica no KernelBrowser.
 */
trait LogsIn
{
    /** Cabeçalhos que o frontend sempre envia (X-Requested-With = proteção CSRF). */
    protected const array UI = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    protected const string TEST_PASSWORD = 'frase-senha de teste 42';

    protected static function createAccount(string $username, string $role = 'admin', bool $mustChange = false, string $password = self::TEST_PASSWORD): int
    {
        $c = static::getContainer();

        return (int) $c->get(Connection::class)->fetchOne(
            "INSERT INTO app_account (username, display_name, password_hash, role, active, must_change_password, created_at, updated_at)
             VALUES (?, ?, ?, ?, true, ?, now(), now()) RETURNING id",
            [$username, ucfirst($username), $c->get(PasswordHasher::class)->hash($password), $role, $mustChange ? 't' : 'f'],
        );
    }

    /** @return array<string, mixed> resposta do login */
    protected function login(KernelBrowser $client, string $username, string $password = self::TEST_PASSWORD, int $expect = 200): array
    {
        $client->request('POST', '/api/ui/auth/login', server: self::UI + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => $username, 'password' => $password], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($expect, (string) $client->getResponse()->getContent());

        /** @var array<string, mixed> */
        return json_decode((string) $client->getResponse()->getContent(), true) ?? [];
    }

    protected function loginAs(KernelBrowser $client, string $role = 'admin'): void
    {
        $username = $role.'.teste';
        self::createAccount($username, $role);
        $this->login($client, $username);
    }
}
