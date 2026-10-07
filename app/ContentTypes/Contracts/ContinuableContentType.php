<?php

namespace App\ContentTypes\Contracts;

interface ContinuableContentType
{
    public function continuationCollectionField(): string;

    public function continuationNumberField(): string;

    public function maximumContinuationEntries(): int;

    public function maximumContinuationEntriesPerRequest(): int;

    public function continuationInstructions(): string;
}
