<?php

namespace App\Actions;

use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AppendGeneratedContentVersion
{
    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>|null  $contextSnapshot
     * @param  array<string, mixed>|null  $generationMetadata
     */
    public function handle(
        GeneratedContent $generatedContent,
        array $content,
        GeneratedContentVersionOrigin $origin,
        ?array $contextSnapshot = null,
        ?array $generationMetadata = null,
    ): GeneratedContentVersion {
        if (! $generatedContent->exists || $generatedContent->getKey() === null) {
            throw new InvalidArgumentException('A version must belong to an existing generated content record.');
        }

        return DB::transaction(function () use (
            $generatedContent,
            $content,
            $origin,
            $contextSnapshot,
            $generationMetadata,
        ): GeneratedContentVersion {
            $lockedContent = GeneratedContent::query()
                ->lockForUpdate()
                ->findOrFail($generatedContent->getKey());

            $latestVersion = $lockedContent->versions()
                ->reorder()
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            return $lockedContent->versions()->create([
                'version_number' => ($latestVersion?->version_number ?? 0) + 1,
                'origin' => $origin,
                'content' => $content,
                'context_snapshot' => $contextSnapshot,
                'generation_metadata' => $generationMetadata,
            ]);
        });
    }
}
