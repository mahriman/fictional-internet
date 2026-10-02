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
        ?GeneratedContentVersion $basedOnVersion = null,
    ): GeneratedContentVersion {
        if (! $generatedContent->exists || $generatedContent->getKey() === null) {
            throw new InvalidArgumentException('A version must belong to an existing generated content record.');
        }

        if ($basedOnVersion !== null && (! $basedOnVersion->exists || $basedOnVersion->getKey() === null)) {
            throw new InvalidArgumentException('A version can only be based on an existing version.');
        }

        return DB::transaction(function () use (
            $generatedContent,
            $content,
            $origin,
            $contextSnapshot,
            $generationMetadata,
            $basedOnVersion,
        ): GeneratedContentVersion {
            $lockedContent = GeneratedContent::query()
                ->lockForUpdate()
                ->findOrFail($generatedContent->getKey());

            $lockedSourceVersion = null;

            if ($basedOnVersion !== null) {
                $lockedSourceVersion = $lockedContent->versions()
                    ->whereKey($basedOnVersion->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedSourceVersion === null) {
                    throw new InvalidArgumentException('A source version must belong to the same generated content record.');
                }
            }

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
                'based_on_version_id' => $lockedSourceVersion?->getKey(),
            ]);
        });
    }
}
