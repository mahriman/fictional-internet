<?php

namespace App\Services\OpenAI;

final readonly class OpenAiResponseResult
{
    public function __construct(
        public string $text,
        public string $responseId,
        public string $model,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $totalTokens,
    ) {}
}
