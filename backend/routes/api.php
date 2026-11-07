<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashSessionController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'changePassword']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show'])->whereNumber('product');

    // Le catalogue est lisible par tous ; sa gestion est reservee aux
    // responsables, car elle expose le prix d'achat.
    Route::middleware('role:Admin|Gérant')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        Route::match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update'])->whereNumber('product');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->whereNumber('product');
    });

    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('suppliers', SupplierController::class);

    // Tableau de bord : statistiques agregees (montants masques aux employes).
    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::middleware('role:Admin|Gérant')->prefix('reports')->group(function () {
        Route::get('/summary', [ReportsController::class, 'summary']);
        Route::get('/{type}/pdf', [ReportsController::class, 'pdf']);
        Route::get('/{type}', [ReportsController::class, 'index']);
    });

    Route::middleware('role:Admin')->prefix('settings/company')->group(function () {
        Route::get('/', [SettingsController::class, 'company']);
        Route::put('/', [SettingsController::class, 'updateCompany']);
        Route::post('/logo', [SettingsController::class, 'uploadLogo']);
    });
    Route::get('/settings/company/logo', [SettingsController::class, 'logo'])->middleware('role:Admin|Gérant');

    // Utilisateurs : accessible aux comptes autorises a gérer les comptes.
    Route::apiResource('users', UserController::class)->middleware('can:gerer_utilisateurs');

    // Caisse : une seule session ouverte par utilisateur.
    Route::post('/cash-sessions/open', [CashSessionController::class, 'open']);
    Route::post('/cash-sessions/close', [CashSessionController::class, 'close']);
    Route::get('/cash-sessions/current', [CashSessionController::class, 'current']);

    // Dépenses : rattachées à la session de caisse ouverte de l'utilisateur.
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);

    // Ventes : enregistrement transactionnel. L'historique et le detail
    // financier sont reserves aux responsables ; chaque vendeur peut imprimer
    // la facture de ses propres ventes.
    Route::post('/sales', [SaleController::class, 'store']);
    Route::get('/sales/{sale}/invoice', [SaleController::class, 'downloadInvoice'])->whereNumber('sale');
    Route::post('/sales/{sale}/payments', [SaleController::class, 'addPayment'])
        ->whereNumber('sale')
        ->middleware('role:Admin|Gérant');

    Route::middleware('role:Admin|Gérant')->group(function () {
        Route::get('/sales', [SaleController::class, 'index']);
        Route::get('/sales/{sale}', [SaleController::class, 'show'])->whereNumber('sale');
    });

    // Listes de référence du catalogue, utilisées par le formulaire produit.
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show'])->whereNumber('category');
    Route::get('/units', [UnitController::class, 'index']);

    // Gestion des catégories : écriture réservée aux responsables.
    Route::middleware('role:Admin|Gérant')->group(function () {
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update'])->whereNumber('category');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->whereNumber('category');
    });

    // L'employé peut voir l'historique
    Route::get('/stock-movements', [StockMovementController::class, 'index']);

    // Seul le gérant/admin peut faire un ajustement
    Route::post('/stock-movements', [StockMovementController::class, 'store'])
        ->middleware('can:ajuster_stock');

    // Achats / Réapprovisionnement : gestion réservée aux responsables.
    Route::middleware('role:Admin|Gérant')->group(function () {
        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase');
        Route::post('/purchases/{id}/receive', [PurchaseController::class, 'receive'])->whereNumber('id');
        Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->whereNumber('purchase');
    });
});
