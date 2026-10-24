<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    // Liste tous les mouvements
    public function index()
    {
        return response()->json(StockMovement::with(['product', 'user'])->latest()->paginate(20));
    }

    // Créer un mouvement (ajustement, entrée, sortie)
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:in,out,adjustment',
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|string'
        ]);

        DB::beginTransaction();
        try {
            $product = Product::findOrFail($validated['product_id']);

            // Création du mouvement
            $movement = StockMovement::create([
                'product_id' => $product->id,
                'user_id' => auth()->id(),
                'type' => $validated['type'],
                'quantity' => $validated['quantity'],
                'note' => $validated['reason'],
            ]);

            // Mise à jour du stock du produit
            if ($validated['type'] === 'in') {
                $product->increment('stock_quantity', $validated['quantity']);
            } elseif ($validated['type'] === 'out') {
                $product->decrement('stock_quantity', $validated['quantity']);
            } elseif ($validated['type'] === 'adjustment') {
                $product->update(['stock_quantity' => $validated['quantity']]);
            }

            DB::commit();
            return response()->json(['message' => 'Stock mis à jour avec succès', 'movement' => $movement], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erreur lors de la mise à jour du stock', 'error' => $e->getMessage()], 500);
        }
    }
}
