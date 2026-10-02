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
        Schema::table('generated_content_versions', function (Blueprint $table) {
            $table->foreignId('based_on_version_id')->nullable();
            $table->index('based_on_version_id', 'gcv_based_on_idx');
            $table->foreign('based_on_version_id', 'gcv_based_on_version_fk')
                ->references('id')
                ->on('generated_content_versions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generated_content_versions', function (Blueprint $table) {
            $table->dropForeign('gcv_based_on_version_fk');
            $table->dropIndex('gcv_based_on_idx');
            $table->dropColumn('based_on_version_id');
        });
    }
};
