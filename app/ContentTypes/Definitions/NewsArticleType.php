<?php

namespace App\ContentTypes\Definitions;

use App\ContentTypes\Contracts\ContentTypeDefinition;

class NewsArticleType implements ContentTypeDefinition
{
    public function key(): string
    {
        return 'news_article';
    }

    public function label(): string
    {
        return 'News article';
    }

    public function promptInstructions(): string
    {
        return 'Write a fictional news article with a clear headline, publication, publication date, and article body.';
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function titleFromContent(array $content): ?string
    {
        $headline = $content['headline'] ?? null;

        return is_string($headline) ? $headline : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'headline' => ['type' => 'string'],
                'publication' => ['type' => 'string'],
                'published_at' => ['type' => 'string', 'format' => 'date-time'],
                'body' => ['type' => 'string'],
            ],
            'required' => ['headline', 'publication', 'published_at', 'body'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function validationRules(): array
    {
        return [
            'headline' => ['required', 'string', 'max:255'],
            'publication' => ['required', 'string', 'max:255'],
            'published_at' => ['required', 'date'],
            'body' => ['required', 'string'],
        ];
    }
}
