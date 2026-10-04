<?php

namespace App\Actions;

use App\ContentTypes\Contracts\ContentTypeDefinition;
use App\Models\Project;

final readonly class PreparedContentGeneration
{
    /**
     * @param  array<string, mixed>  $contextSnapshot
     */
    public function __construct(
        public Project $project,
        public string $contentType,
        public ContentTypeDefinition $definition,
        public string $generationInput,
        public array $contextSnapshot,
    ) {}
}
