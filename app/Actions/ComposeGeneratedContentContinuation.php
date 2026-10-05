<?php

namespace App\Actions;

use App\ContentTypes\ContentContinuationComposer;
use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Contracts\ContinuableContentType;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use InvalidArgumentException;

class ComposeGeneratedContentContinuation
{
    public function __construct(
        private ContentTypeRegistry $contentTypes,
        private ContentContinuationComposer $composer,
    ) {}

    /**
     * Resolve the exact persisted source through its requested content, then compose and validate additions.
     *
     * @param  array<string, mixed>  $proposal  A strict object containing only an `entries` list.
     * @return array<string, mixed>
     */
    public function handle(
        GeneratedContent $generatedContent,
        GeneratedContentVersion $sourceVersion,
        array $proposal,
    ): array {
        $source = $this->resolve($generatedContent, $sourceVersion);

        return $this->composer->compose($source->definition, $source->sourceContent, $proposal);
    }

    public function resolve(
        GeneratedContent $generatedContent,
        GeneratedContentVersion $sourceVersion,
    ): ResolvedGeneratedContentContinuation {
        if (! $generatedContent->exists || $generatedContent->getKey() === null) {
            throw new InvalidArgumentException('A continuation requires an existing generated content record.');
        }

        if (! $sourceVersion->exists || $sourceVersion->getKey() === null) {
            throw new InvalidArgumentException('A continuation requires an existing source version.');
        }

        $persistedContent = GeneratedContent::query()->find($generatedContent->getKey());
        $persistedSource = $persistedContent?->versions()
            ->whereKey($sourceVersion->getKey())
            ->first();

        if ($persistedContent === null || $persistedSource === null) {
            throw new InvalidArgumentException('The source version must belong to the requested generated content.');
        }

        $definition = $this->contentTypes->get($persistedContent->content_type);

        if (! $definition instanceof ContinuableContentType) {
            throw new InvalidArgumentException('This content type does not support continuation.');
        }

        if (! is_array($persistedSource->content)) {
            throw new InvalidArgumentException('The source version does not contain a structured document.');
        }

        $this->composer->proposalSchema($definition, $persistedSource->content);

        return new ResolvedGeneratedContentContinuation(
            $persistedContent,
            $persistedSource,
            $definition,
            $persistedSource->content,
        );
    }
}
