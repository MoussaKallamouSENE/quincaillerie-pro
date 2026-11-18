<?php

namespace App\Exceptions;

use App\Models\CashSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le tiroir de caisse ne contient pas assez d'especes pour le remboursement.
 */
class InsufficientCashException extends RuntimeException
{
    public function __construct(
        public readonly CashSession $cashSession,
        public readonly float $expectedCash,
        public readonly float $requestedAmount,
    ) {
        parent::__construct(sprintf(
            'Solde de caisse insuffisant : %s FCFA disponibles, %s FCFA demandés.',
            number_format($expectedCash, 2, '.', ''),
            number_format($requestedAmount, 2, '.', ''),
        ));
    }

    /**
     * Rendre le refus au client.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage()],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
