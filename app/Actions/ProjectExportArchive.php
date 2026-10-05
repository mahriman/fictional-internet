<?php

namespace App\Actions;

class ProjectExportArchive
{
    public function __construct(
        public readonly string $filename,
        public readonly string $body,
    ) {}
}
