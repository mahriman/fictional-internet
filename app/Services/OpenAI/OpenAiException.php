<?php

namespace App\Services\OpenAI;

use RuntimeException;
use Throwable;

class OpenAiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }
}
