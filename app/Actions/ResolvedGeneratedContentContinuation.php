<?php

namespace App\Actions;

use App\ContentTypes\Contracts\ContentTypeDefinition;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;

final readonly class ResolvedGeneratedContentContinuation
{
    /**
     * @param  array<string, mixed>  $sourceContent
     */
    public function __construct(
        public GeneratedContent $generatedContent,
        public GeneratedContentVersion $sourceVersion,
        public ContentTypeDefinition $definition,
        public array $sourceContent,
    ) {}
}
