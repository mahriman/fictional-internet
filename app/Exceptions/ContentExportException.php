<?php

namespace App\Exceptions;

use RuntimeException;

class ContentExportException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 503)
    {
        parent::__construct($message);
    }
}
