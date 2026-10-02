<?php

namespace App\Actions;

use App\Services\OpenAI\OpenAiResponseResult;

final readonly class StructuredContentGenerationResult
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        public array $content,
        public OpenAiResponseResult $response,
    ) {}
}
