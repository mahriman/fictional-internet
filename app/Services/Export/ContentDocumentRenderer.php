<?php

namespace App\Services\Export;

use App\Enums\ContentExportFormat;

interface ContentDocumentRenderer
{
    public function render(string $html, ContentExportFormat $format): string;
}
