<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('generation_attempts', function (Blueprint $table) {
            $table->foreignId('target_generated_content_id')->nullable()
                ->constrained('generated_contents', 'id', 'gen_attempt_target_content_fk')
                ->cascadeOnDelete();
            $table->foreignId('source_version_id')->nullable()
                ->constrained('generated_content_versions', 'id', 'gen_attempt_source_version_fk')
                ->cascadeOnDelete();
            $table->foreignId('generated_content_version_id')->nullable()
                ->constrained('generated_content_versions', 'id', 'gen_attempt_result_version_fk')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::create('generation_attempts_backup', function (Blueprint $table) {
                $table->id();
                $table->char('token_hash', 64);
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('generated_content_id')->nullable();
                $table->string('status', 16);
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });

            $columns = [
                'id', 'token_hash', 'user_id', 'project_id', 'generated_content_id',
                'status', 'claimed_at', 'completed_at', 'created_at', 'updated_at',
            ];
            DB::table('generation_attempts_backup')->insertUsing(
                $columns,
                DB::table('generation_attempts')->select($columns),
            );
            Schema::drop('generation_attempts');
            Schema::create('generation_attempts', function (Blueprint $table) {
                $table->id();
                $table->char('token_hash', 64)->unique('gen_attempt_token_hash_unique');
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('generated_content_id')->nullable()->constrained()->nullOnDelete();
                $table->string('status', 16);
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'project_id', 'status'], 'gen_attempt_owner_state_index');
            });
            DB::table('generation_attempts')->insertUsing(
                $columns,
                DB::table('generation_attempts_backup')->select($columns),
            );
            Schema::drop('generation_attempts_backup');

            return;
        }

        Schema::table('generation_attempts', function (Blueprint $table) {
            $table->dropForeign('gen_attempt_target_content_fk');
            $table->dropForeign('gen_attempt_source_version_fk');
            $table->dropForeign('gen_attempt_result_version_fk');
            $table->dropColumn([
                'target_generated_content_id',
                'source_version_id',
                'generated_content_version_id',
            ]);
        });
    }
};
