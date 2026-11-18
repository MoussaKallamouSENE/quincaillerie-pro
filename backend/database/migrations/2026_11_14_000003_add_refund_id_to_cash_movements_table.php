<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('refund_id')
                ->nullable()
                ->after('user_id')
                ->constrained('refunds')
                ->restrictOnDelete();
            $table->unique('refund_id', 'cash_movements_refund_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropForeign(['refund_id']);
            $table->dropUnique('cash_movements_refund_id_unique');
            $table->dropColumn('refund_id');
        });
    }
};
