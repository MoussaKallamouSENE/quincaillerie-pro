<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'utilisateur dispose deja d'une session de caisse ouverte.
 */
class CashSessionAlreadyOpenException extends RuntimeException
{
    public function __construct(
        string $message = 'Une session de caisse est déjà ouverte. Fermez-la avant d’en ouvrir une nouvelle.',
    ) {
        parent::__construct($message);
    }

    /**
     * Rendre l'erreur au client.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage()],
            Response::HTTP_CONFLICT,
        );
    }
}
