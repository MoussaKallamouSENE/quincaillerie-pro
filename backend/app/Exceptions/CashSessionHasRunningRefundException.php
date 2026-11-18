<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * La session ne peut pas etre close tant qu'un remboursement especes
 * n'est pas termine.
 */
class CashSessionHasRunningRefundException extends RuntimeException
{
    public function __construct(
        string $message = 'Cette session de caisse contient un remboursement en cours. Terminez-le avant de clôturer la session.',
    ) {
        parent::__construct($message);
    }

    /**
     * Rendre le conflit au client.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage()],
            Response::HTTP_CONFLICT,
        );
    }
}
