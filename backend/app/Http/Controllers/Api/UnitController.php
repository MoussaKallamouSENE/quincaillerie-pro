<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnitController extends Controller
{
    /**
     * Display the list of the catalogue units.
     *
     * Cette liste alimente les listes de sélection du formulaire de création
     * d'un produit.
     */
    public function index(): AnonymousResourceCollection
    {
        return UnitResource::collection(
            Unit::query()->orderBy('name')->get()
        );
    }
}
