<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('status', 20)->default('confirmed')->after('method');
            $table->foreignId('confirmed_by_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by_id');
            $table->foreignId('rejected_by_id')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by_id');
            $table->string('rejection_reason', 500)->nullable()->after('rejected_at');
            $table->string('idempotency_key', 64)->nullable()->after('rejection_reason');

            $table->unique(['method', 'reference'], 'payments_method_reference_unique');
            $table->unique(['sale_id', 'idempotency_key'], 'payments_sale_idempotency_key_unique');
            $table->index(['status', 'method'], 'payments_status_method_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasIndex('payments', 'payments_sale_id_foreign')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->index('sale_id', 'payments_sale_id_foreign');
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_method_reference_unique');
            $table->dropUnique('payments_sale_idempotency_key_unique');
            $table->dropIndex('payments_status_method_index');

            $table->dropConstrainedForeignId('confirmed_by_id');
            $table->dropConstrainedForeignId('rejected_by_id');
            $table->dropColumn([
                'status',
                'confirmed_at',
                'rejected_at',
                'rejection_reason',
                'idempotency_key',
            ]);
        });
    }
};
