<?php

namespace App\ContentTypes\Contracts;

interface GeneratedContinuationNormalizer
{
    /**
     * @param  array<string, mixed>  $combinedContent
     * @return array{
     *     content: array<string, mixed>,
     *     quotes_preserved: int,
     *     quotes_normalized: int,
     *     quotes_dropped: int
     * }
     */
    public function normalizeGeneratedContinuation(array $combinedContent, int $sourceEntryCount): array;
}
