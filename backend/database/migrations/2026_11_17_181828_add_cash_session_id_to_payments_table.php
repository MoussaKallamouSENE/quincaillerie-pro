<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_session_id')
                ->nullable()
                ->after('user_id')
                ->constrained('cash_sessions')
                ->restrictOnDelete();

            $table->index(
                ['cash_session_id', 'method', 'status'],
                'payments_cash_session_method_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cash_session_id']);
            $table->dropIndex('payments_cash_session_method_status_index');
            $table->dropColumn('cash_session_id');
        });
    }
};
