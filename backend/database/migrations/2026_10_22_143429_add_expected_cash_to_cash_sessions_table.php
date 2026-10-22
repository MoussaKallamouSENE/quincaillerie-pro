<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Le solde theorique du tiroir (fond de caisse + ventes en especes -
     * depenses) est fige a la cloture de la session : une depense enregistree
     * apres coup ne doit pas modifier l'historique de la caisse.
     */
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->decimal('expected_cash', 12, 2)->nullable()->after('closing_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropColumn('expected_cash');
        });
    }
};
