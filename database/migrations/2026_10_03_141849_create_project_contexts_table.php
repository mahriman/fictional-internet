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
        Schema::create('project_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->text('setting')->nullable();
            $table->text('time_period')->nullable();
            $table->text('locations')->nullable();
            $table->text('people')->nullable();
            $table->text('organizations')->nullable();
            $table->text('canon_notes')->nullable();
            $table->timestamps();

            $table->unique('project_id', 'project_contexts_project_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_contexts');
    }
};
