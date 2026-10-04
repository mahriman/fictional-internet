<?php

namespace App\ContentTypes\Contracts;

interface GeneratedContentNormalizer
{
    /**
     * @param  array<string, mixed>  $content
     * @return array{
     *     content: array<string, mixed>,
     *     quotes_preserved: int,
     *     quotes_normalized: int,
     *     quotes_dropped: int
     * }
     */
    public function normalizeGeneratedContent(array $content): array;
}
