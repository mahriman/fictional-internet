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
        Schema::table('generated_contents', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        DB::table('generated_contents')->whereNull('uuid')->orderBy('id')->chunkById(100, function (Collection $generatedContents): void {
            foreach ($generatedContents as $generatedContent) {
                DB::table('generated_contents')->where('id', $generatedContent->id)->update([
                    'uuid' => (string) Str::uuid(),
                ]);
            }
        });

        Schema::table('generated_contents', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->unique('uuid', 'generated_contents_uuid_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generated_contents', function (Blueprint $table) {
            $table->dropUnique('generated_contents_uuid_unique');
            $table->dropColumn('uuid');
        });
    }
};
