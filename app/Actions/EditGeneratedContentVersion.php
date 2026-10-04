<?php

namespace App\Actions;

use App\ContentTypes\ContentTypeRegistry;
use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContentVersion;
use InvalidArgumentException;

class EditGeneratedContentVersion
{
    public function __construct(
        private AppendGeneratedContentVersion $appendVersion,
        private ContentTypeRegistry $contentTypes,
    ) {}

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
        $definition = $this->contentTypes->all()[$generatedContent->content_type] ?? null;

        if ($definition !== null) {
            $editedContent = $definition->prepareEditedContent(
                $editedContent,
                is_array($persistedSourceVersion->content) ? $persistedSourceVersion->content : [],
            );
        }

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
