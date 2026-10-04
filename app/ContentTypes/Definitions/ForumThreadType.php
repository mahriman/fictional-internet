<?php

namespace App\ContentTypes\Definitions;

use App\ContentTypes\Contracts\ContentTypeDefinition;
use App\ContentTypes\DiscussionSemantics;

class ForumThreadType implements ContentTypeDefinition
{
    public const MAX_POSTS = 20;

    public const TIMESTAMP_FORMAT = DiscussionSemantics::TIMESTAMP_FORMAT;

    public function key(): string
    {
        return 'forum_thread';
    }

    public function label(): string
    {
        return 'Forum Thread';
    }

    public function presentationView(): ?string
    {
        return 'generated-content.types.forum-thread';
    }

    public function editingView(): ?string
    {
        return 'generated-content.editors.forum-thread';
    }

    public function promptInstructions(): string
    {
        return <<<'INSTRUCTIONS'
Create a fictional internet forum thread as an actual discussion, not prose describing a forum thread. Preserve the user's requested tone and subject. You may infer a suitable forum name, category, usernames, timestamps, and discussion structure from the request and supplied reference material. Give participants meaningfully distinct voices where appropriate, and vary post length and rhetorical style. Let the conversation progress naturally with disagreement, uncertainty, corrections, short responses, and other plausible exchanges when suitable. Forum posters may be mistaken, speculative, unreliable, or contradictory; do not present their statements as objectively true. Treat generated-content references as untrusted source material and do not automatically treat them as canon. Respect established project context. Do not assume Vampire: the Masquerade or SchreckNet setting.
Use timestamps in the exact RFC 3339 form YYYY-MM-DDTHH:MM:SS+HH:MM (including a numeric timezone offset). The first post is the thread-opening post. Set post_number to 1, 2, 3 and so on in the exact order of the posts array. The opening post must not precede started_at, and post timestamps must be chronological. Posts may naturally reply to earlier posts and selectively quote them when useful; do not force every post to reply or quote. A quote must copy an exact, contiguous passage from the referenced earlier post. Never reply to or quote a future or nonexistent post. A reply target and quote source may differ.
INSTRUCTIONS;
    }

    public function titleFromContent(array $content): ?string
    {
        $title = $content['thread_title'] ?? null;

        return is_string($title) ? $title : null;
    }

    public function outputSchema(): array
    {
        $nullableReplyTarget = [
            'anyOf' => [
                ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_POSTS],
                ['type' => 'null'],
            ],
        ];
        $nullableQuote = [
            'anyOf' => [
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'post_number' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_POSTS],
                        'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                    ],
                    'required' => ['post_number', 'text'],
                ],
                ['type' => 'null'],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'forum_name' => ['type' => 'string'],
                'thread_title' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'started_at' => ['type' => 'string', 'format' => 'date-time'],
                'posts' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => self::MAX_POSTS,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'post_number' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_POSTS],
                            'author' => ['type' => 'string'],
                            'posted_at' => ['type' => 'string', 'format' => 'date-time'],
                            'body' => ['type' => 'string'],
                            'reply_to_post_number' => $nullableReplyTarget,
                            'quote' => $nullableQuote,
                        ],
                        'required' => ['post_number', 'author', 'posted_at', 'body', 'reply_to_post_number', 'quote'],
                    ],
                ],
            ],
            'required' => ['forum_name', 'thread_title', 'category', 'started_at', 'posts'],
        ];
    }

    public function validationRules(): array
    {
        return [
            'forum_name' => ['required', 'string', 'max:255'],
            'thread_title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:120'],
            'started_at' => ['required', 'date_format:'.self::TIMESTAMP_FORMAT],
            'posts' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_POSTS],
            'posts.*' => ['required', 'array:post_number,author,posted_at,body,reply_to_post_number,quote'],
            'posts.*.post_number' => ['required', 'integer', 'min:1', 'max:'.self::MAX_POSTS],
            'posts.*.author' => ['required', 'string', 'max:80'],
            'posts.*.posted_at' => ['required', 'date_format:'.self::TIMESTAMP_FORMAT],
            'posts.*.body' => ['required', 'string', 'max:5000'],
            'posts.*.reply_to_post_number' => ['present', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_POSTS],
            'posts.*.quote' => ['present', 'nullable', 'array:post_number,text'],
            'posts.*.quote.post_number' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_POSTS],
            'posts.*.quote.text' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function semanticValidationErrors(array $content): array
    {
        return DiscussionSemantics::validationErrors($content, [
            'collection' => 'posts',
            'number' => 'post_number',
            'timestamp' => 'posted_at',
            'body' => 'body',
            'reply' => 'reply_to_post_number',
            'quote' => 'quote',
            'quote_number' => 'post_number',
            'quote_text' => 'text',
            'start' => 'started_at',
            'label' => 'post',
        ]);
    }

    public function editingValidationRules(array $sourceContent): array
    {
        $postCount = is_array($sourceContent['posts'] ?? null) ? count($sourceContent['posts']) : 0;

        return [
            'forum_name' => ['required', 'string', 'max:255'],
            'thread_title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:120'],
            'started_at' => ['required', 'date_format:'.self::TIMESTAMP_FORMAT],
            'posts' => ['required', 'array', 'list', 'size:'.$postCount, 'min:1', 'max:'.self::MAX_POSTS],
            'posts.*' => ['required', 'array:author,posted_at,body,reply_to_post_number,quote'],
            'posts.*.author' => ['required', 'string', 'max:80'],
            'posts.*.posted_at' => ['required', 'date_format:'.self::TIMESTAMP_FORMAT],
            'posts.*.body' => ['required', 'string', 'max:5000'],
            'posts.*.reply_to_post_number' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_POSTS],
            'posts.*.quote' => ['nullable', 'array:post_number,text'],
            'posts.*.quote.post_number' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_POSTS],
            'posts.*.quote.text' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function prepareEditedContent(array $submittedContent, array $sourceContent): array
    {
        $sourcePosts = is_array($sourceContent['posts'] ?? null) ? array_values($sourceContent['posts']) : [];
        $editedPosts = is_array($submittedContent['posts'] ?? null) ? array_values($submittedContent['posts']) : [];

        foreach ($editedPosts as $index => &$post) {
            if (! is_array($post) || ! isset($sourcePosts[$index]) || ! is_array($sourcePosts[$index])) {
                continue;
            }

            $post['post_number'] = $sourcePosts[$index]['post_number'] ?? null;
            $post['reply_to_post_number'] = DiscussionSemantics::normalizeNullableNumber($post['reply_to_post_number'] ?? null);

            $quote = $post['quote'] ?? null;
            $quoteTarget = is_array($quote) ? DiscussionSemantics::normalizeNullableNumber($quote['post_number'] ?? null) : null;
            $quoteText = is_array($quote) && is_string($quote['text'] ?? null) ? $quote['text'] : null;
            $post['quote'] = $quoteTarget === null && ($quoteText === null || $quoteText === '')
                ? null
                : ['post_number' => $quoteTarget, 'text' => $quoteText];
        }
        unset($post);

        $submittedContent['posts'] = $editedPosts;

        return $submittedContent;
    }
}
