<?php

namespace App\ContentTypes\Contracts;

interface ContinuableContentType
{
    public function continuationCollectionField(): string;

    public function continuationNumberField(): string;

    public function continuationInstructions(): string;
}
