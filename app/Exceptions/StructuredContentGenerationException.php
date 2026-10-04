<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class StructuredContentGenerationException extends RuntimeException
{
    /**
     * @param  list<string>  $fieldPaths
     * @param  list<string>  $diagnosticCodes
     * @param  array<string, int|string|bool|null>  $diagnosticContext
     */
    public function __construct(
        string $message,
        public readonly string $diagnosticCategory = 'unknown',
        public readonly array $fieldPaths = [],
        ?Throwable $previous = null,
        public readonly array $diagnosticCodes = [],
        public readonly array $diagnosticContext = [],
    ) {
        parent::__construct($message, 0, $previous);
    }
}
