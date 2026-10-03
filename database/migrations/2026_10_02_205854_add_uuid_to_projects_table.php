<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        DB::table('projects')->whereNull('uuid')->orderBy('id')->chunkById(100, function (Collection $projects): void {
            foreach ($projects as $project) {
                DB::table('projects')->where('id', $project->id)->update([
                    'uuid' => (string) Str::uuid(),
                ]);
            }
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->unique('uuid', 'projects_uuid_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique('projects_uuid_unique');
            $table->dropColumn('uuid');
        });
    }
};
