<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained('refunds')->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 30)->default('execution_proof');
            $table->string('disk', 50)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->timestamps();

            $table->unique(['disk', 'path'], 'refund_documents_disk_path_unique');
            $table->unique(['refund_id', 'checksum'], 'refund_documents_refund_checksum_unique');
            $table->index(['refund_id', 'created_at'], 'refund_documents_refund_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_documents');
    }
};
