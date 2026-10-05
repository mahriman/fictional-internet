<?php

namespace App\Actions;

final readonly class PreparedGeneratedContentContinuation
{
    /**
     * @param  array<string, mixed>  $proposalSchema
     */
    public function __construct(
        public ResolvedGeneratedContentContinuation $source,
        public PreparedContentGeneration $generation,
        public array $proposalSchema,
        public string $instructions,
        public int $requestedEntryCount,
    ) {}
}
