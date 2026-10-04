<?php

namespace App\Services\Export;

final readonly class ContentExportDocument
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $body,
    ) {}
}
