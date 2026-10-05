<?php

namespace App\Actions;

use App\Models\GeneratedContentVersion;

final readonly class ContentContinuationGenerationResult
{
    /**
     * @param  array<string, mixed>  $generationMetadata
     */
    public function __construct(
        public GeneratedContentVersion $version,
        public array $generationMetadata,
        public bool $alreadyCompleted = false,
    ) {}
}
