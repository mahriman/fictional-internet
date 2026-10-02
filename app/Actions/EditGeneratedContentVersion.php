<?php

namespace App\Actions;

use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContentVersion;
use InvalidArgumentException;

class EditGeneratedContentVersion
{
    public function __construct(private AppendGeneratedContentVersion $appendVersion) {}

    /**
     * @param  array<string, mixed>  $editedContent
     */
    public function handle(
        GeneratedContentVersion $sourceVersion,
        array $editedContent,
    ): GeneratedContentVersion {
        if (! $sourceVersion->exists || $sourceVersion->getKey() === null) {
            throw new InvalidArgumentException('An edit must be based on an existing version.');
        }

        $persistedSourceVersion = GeneratedContentVersion::query()->findOrFail($sourceVersion->getKey());
        $generatedContent = $persistedSourceVersion->generatedContent()->firstOrFail();

        return $this->appendVersion->handle(
            $generatedContent,
            $editedContent,
            GeneratedContentVersionOrigin::UserEdited,
            $persistedSourceVersion->context_snapshot,
            null,
            $persistedSourceVersion,
        );
    }
}
