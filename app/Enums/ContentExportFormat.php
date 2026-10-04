<?php

namespace App\Enums;

enum ContentExportFormat: string
{
    case Html = 'html';
    case Pdf = 'pdf';
    case Png = 'png';

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Html => 'text/html; charset=UTF-8',
            self::Pdf => 'application/pdf',
            self::Png => 'image/png',
        };
    }
}
