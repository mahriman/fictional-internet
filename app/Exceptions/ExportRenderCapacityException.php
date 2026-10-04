<?php

namespace App\Exceptions;

class ExportRenderCapacityException extends ContentExportException
{
    public function __construct()
    {
        parent::__construct('Export capacity is temporarily busy. Please try again shortly.');
    }

    public function retryAfterSeconds(): int
    {
        return 10;
    }
}
