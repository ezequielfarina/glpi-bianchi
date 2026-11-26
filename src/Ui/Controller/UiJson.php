<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Ui\Write\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Helpers comuns dos controllers /api/ui. */
trait UiJson
{
    /** Quem fez a alteração. Fase 2 (login individual): usuário autenticado. */
    private const string ACTOR = 'painel';

    /** @param array<array-key, mixed> $data */
    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }
        try {
            $data = json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(['body' => 'JSON inválido.']);
        }
        if (!\is_array($data)) {
            throw new ValidationException(['body' => 'Envie um objeto JSON.']);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
