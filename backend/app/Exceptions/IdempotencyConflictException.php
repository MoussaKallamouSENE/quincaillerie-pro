<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une cle d'idempotence a deja servi pour une operation differente.
 */
class IdempotencyConflictException extends RuntimeException
{
    public function __construct(
        string $message = 'Cette clé d’idempotence a déjà été utilisée avec des données différentes.',
    ) {
        parent::__construct($message);
    }

    /**
     * Rendre le conflit au client.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'idempotency_key_conflict',
        ], Response::HTTP_CONFLICT);
    }
}
