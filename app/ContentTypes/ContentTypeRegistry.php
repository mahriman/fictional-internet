<?php

namespace App\ContentTypes;

use App\ContentTypes\Contracts\ContentTypeDefinition;
use InvalidArgumentException;

class ContentTypeRegistry
{
    /**
     * @var array<string, ContentTypeDefinition>
     */
    private array $definitions = [];

    public function __construct(ContentTypeDefinition ...$definitions)
    {
        foreach ($definitions as $definition) {
            $key = $definition->key();

            if (array_key_exists($key, $this->definitions)) {
                throw new InvalidArgumentException("Content type key [{$key}] is already registered.");
            }

            $this->definitions[$key] = $definition;
        }
    }

    public function get(string $key): ContentTypeDefinition
    {
        return $this->definitions[$key]
            ?? throw new InvalidArgumentException("Content type key [{$key}] is not registered.");
    }

    /**
     * @return array<string, ContentTypeDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
