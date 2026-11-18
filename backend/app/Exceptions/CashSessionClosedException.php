<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class CashSessionClosedException extends RuntimeException
{
    public function __construct(
        string $message = 'La session de caisse d’origine de cette vente est fermée. Impossible d’encaisser ce règlement.',
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage()],
            Response::HTTP_CONFLICT,
        );
    }
}
