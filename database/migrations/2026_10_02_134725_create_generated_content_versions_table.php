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
        Schema::create('generated_content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_content_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('origin', 32);
            $table->json('content');
            $table->json('context_snapshot')->nullable();
            $table->json('generation_metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['generated_content_id', 'version_number'], 'content_version_number_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_content_versions');
    }
};
