<?php

namespace App\Exceptions;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stock insuffisant pour solder une vente.
 *
 * L'exception est levee à l'intérieur de la transaction de la vente : elle
 * annule donc l'ensemble des ecritures (lignes, paiements, décrémentations
 * de stock) déjà effectuées.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly Product $product,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Stock insuffisant pour « %s » : %d disponible(s), %d demandé(s).',
            $product->name,
            $available,
            $requested,
        ));
    }

    /**
     * Rendre l'erreur au client.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage()],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
