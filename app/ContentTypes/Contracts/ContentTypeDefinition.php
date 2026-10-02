<?php

namespace App\ContentTypes\Contracts;

interface ContentTypeDefinition
{
    public function key(): string;

    public function label(): string;

    public function promptInstructions(): string;

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(): array;

    /**
     * @return array<string, array<int, string>>
     */
    public function validationRules(): array;
}
