<?php

namespace App\Actions;

use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;

final readonly class GeneratedContentGenerationResult
{
    /**
     * @param  array{provider: string, model: string, response_id: string, input_tokens: int|null, output_tokens: int|null, total_tokens: int|null, content_type: string}  $generationMetadata
     */
    public function __construct(
        public GeneratedContent $generatedContent,
        public GeneratedContentVersion $version,
        public array $generationMetadata,
    ) {}
}
