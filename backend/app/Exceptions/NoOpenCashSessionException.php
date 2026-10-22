<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aucune session de caisse ouverte pour l'utilisateur.
 *
 * Les ventes sont rattachées à une session de caisse (colonne non nullable
 * `sales.cash_session_id`) : sans session ouverte, aucune vente ne peut être
 * enregistrée.
 */
class NoOpenCashSessionException extends RuntimeException
{
    public function __construct(
        string $message = 'Aucune session de caisse n’est ouverte. Ouvrez-en une avant de vendre.',
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
