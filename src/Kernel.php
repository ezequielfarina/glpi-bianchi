<?php

declare(strict_types=1);

namespace App;

use App\Shared\Doctrine\Type\SealedJsonType;
use App\Shared\Security\SecretBox;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();
        // tipos do DBAL não recebem injeção: o tipo cifrado usa o mesmo SecretBox dos serviços (ADR-0019)
        $box = $this->getContainer()->get(SecretBox::class);
        if ($box instanceof SecretBox) {
            SealedJsonType::useBox($box);
        }
    }
}
