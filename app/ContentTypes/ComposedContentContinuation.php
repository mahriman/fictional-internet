<?php

namespace App\ContentTypes;

final readonly class ComposedContentContinuation
{
    /**
     * @param  array<string, mixed>  $content
     * @param  array{quotes_preserved: int, quotes_normalized: int, quotes_dropped: int}  $quoteNormalization
     */
    public function __construct(
        public array $content,
        public array $quoteNormalization,
    ) {}
}
