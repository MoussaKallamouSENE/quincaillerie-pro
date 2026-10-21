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
                Schema::create('sales', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null');
                    $table->foreignId('user_id')->constrained('users');
                    $table->foreignId('cash_session_id')->constrained('cash_sessions');
                    $table->string('reference')->unique();
                    $table->decimal('total_amount', 12, 2);
                    $table->enum('payment_status', ['paid', 'partial', 'credit'])->default('paid');
                    $table->timestamps();
                });
            }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
