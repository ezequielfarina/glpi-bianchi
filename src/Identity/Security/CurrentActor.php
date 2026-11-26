<?php

declare(strict_types=1);

namespace App\Identity\Security;

use Symfony\Bundle\SecurityBundle\Security;

/** Quem está fazendo a alteração (vai para o histórico dos ativos e anexos). */
final readonly class CurrentActor
{
    public function __construct(private Security $security)
    {
    }

    public function name(): string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? 'sistema';
    }

    public function account(): ?AuthenticatedAccount
    {
        $u = $this->security->getUser();

        return $u instanceof AuthenticatedAccount ? $u : null;
    }
}
