<?php

namespace App\Actions;

use App\Models\GenerationAttempt;

final readonly class GenerationAttemptClaim
{
    public function __construct(
        public GenerationAttempt $attempt,
        public bool $claimed,
    ) {}
}
