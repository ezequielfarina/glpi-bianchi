<?php

declare(strict_types=1);

namespace App\Wifi\Unifi;

use App\Ui\Write\NotFoundException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Uma sessão por controller durante a requisição/comando: aplicar em 3 redes ou sincronizar 9 sites faz UM
 * login — o UniFi bloqueia por excesso de logins (HTTP 429/499). Logout no fim (kernel/console terminate).
 */
final class UnifiSessions implements ResetInterface
{
    /** @var array<int, UnifiConnection> */
    private array $open = [];

    public function __construct(
        private readonly Connection $db,
        private readonly UnifiClientFactory $factory,
    ) {
    }

    public function get(int $controllerId): UnifiConnection
    {
        if (isset($this->open[$controllerId])) {
            return $this->open[$controllerId];
        }
        $row = $this->db->fetchAssociative('SELECT * FROM unifi_controller WHERE id = ?', [$controllerId]);
        if (false === $row) {
            throw new NotFoundException('Controller UniFi não encontrado.');
        }

        return $this->open[$controllerId] = $this->factory->connect($row);
    }

    /** Depois de erro: a próxima chamada abre sessão nova (credencial trocada, sessão expirada…). */
    public function drop(int $controllerId): void
    {
        if (isset($this->open[$controllerId])) {
            $this->open[$controllerId]->logout();
            unset($this->open[$controllerId]);
        }
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function closeAll(): void
    {
        foreach (array_keys($this->open) as $id) {
            $this->drop($id);
        }
    }

    public function reset(): void
    {
        $this->closeAll();
    }
}
