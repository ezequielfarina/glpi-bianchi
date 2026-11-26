<?php

declare(strict_types=1);

namespace App\Identity\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/** 403 em JSON quando o perfil não permite a ação (ex.: "Leitura" tentando editar). */
final class JsonAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        $r = new JsonResponse(['error' => 'Seu perfil não permite esta ação.', 'code' => 'forbidden'], Response::HTTP_FORBIDDEN);
        $r->setEncodingOptions(\JSON_UNESCAPED_UNICODE);

        return $r;
    }
}
