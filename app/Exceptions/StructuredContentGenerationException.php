<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class StructuredContentGenerationException extends RuntimeException
{
    /**
     * @param  list<string>  $fieldPaths
     */
    public function __construct(
        string $message,
        public readonly string $diagnosticCategory = 'unknown',
        public readonly array $fieldPaths = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
