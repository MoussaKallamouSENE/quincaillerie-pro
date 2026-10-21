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
    Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('sale_id')->constrained('sales')->onDelete('cascade');
        $table->foreignId('user_id')->constrained('users');
        $table->decimal('amount', 12, 2);
        $table->enum('method', ['cash', 'wave', 'orange_money', 'bank_transfer', 'check']);
        $table->string('reference')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
