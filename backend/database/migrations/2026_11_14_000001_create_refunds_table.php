<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rejected_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reconciled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained('cash_sessions')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['cash', 'wave', 'orange_money', 'bank_transfer', 'check']);
            $table->string('transaction_reference', 255)->nullable();
            $table->string('reason', 500);
            $table->string('status', 30)->default('pending');
            $table->decimal('cumulative_amount', 12, 2)->nullable();
            $table->string('blocked_reason', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->string('reconciliation_reason', 500)->nullable();
            $table->string('reconciliation_reference', 255)->nullable();
            $table->string('idempotency_key', 64);
            $table->char('idempotency_fingerprint', 64);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('execution_started_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('reconciliation_required_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'idempotency_key'], 'refunds_payment_idempotency_unique');
            $table->unique(['method', 'transaction_reference'], 'refunds_method_reference_unique');
            $table->index(['payment_id', 'status', 'amount'], 'refunds_payment_status_amount_index');
            $table->index(['status', 'created_at'], 'refunds_status_created_index');
            $table->index(['sale_id', 'created_at'], 'refunds_sale_created_index');
            $table->index(['requested_by_id', 'created_at'], 'refunds_requester_created_index');
            $table->index(['cash_session_id', 'status'], 'refunds_cash_session_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
