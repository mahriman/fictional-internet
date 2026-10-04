<?php

namespace App\ContentTypes\Definitions;

use App\ContentTypes\Contracts\ContentTypeDefinition;
use DateTimeImmutable;

class ForumThreadType implements ContentTypeDefinition
{
    public const MAX_POSTS = 20;

    public const TIMESTAMP_FORMAT = 'Y-m-d\\TH:i:sP';

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
        $errors = [];
        $startedAt = $this->parseTimestamp($content['started_at'] ?? null);
        $previousPostAt = null;
        $posts = is_array($content['posts'] ?? null) ? array_values($content['posts']) : [];

        if ($startedAt === null) {
            $errors['started_at'][] = 'The thread start time must use a valid timestamp with a numeric timezone offset.';
        }

        foreach ($posts as $index => $post) {
            if (! is_array($post)) {
                continue;
            }

            if (($post['post_number'] ?? null) !== $index + 1) {
                $errors["posts.{$index}.post_number"][] = 'Post numbers must start at 1 and increase by one in displayed order.';
            }

            $postNumber = $post['post_number'] ?? null;
            $replyTarget = $post['reply_to_post_number'] ?? null;

            if ($replyTarget !== null && (! is_int($replyTarget) || ! is_int($postNumber) || $replyTarget < 1 || $replyTarget >= $postNumber)) {
                $errors["posts.{$index}.reply_to_post_number"][] = 'A reply must refer to an earlier post in this thread.';
            }

            $quote = $post['quote'] ?? null;

            if ($quote !== null) {
                if (! is_array($quote) || array_diff(array_keys($quote), ['post_number', 'text']) !== []) {
                    $errors["posts.{$index}.quote"][] = 'The quote must contain only a source post number and exact quoted text.';
                } else {
                    $quoteTarget = $quote['post_number'] ?? null;
                    $quoteText = $quote['text'] ?? null;

                    if (! is_int($quoteTarget) || ! is_int($postNumber) || $quoteTarget < 1 || $quoteTarget >= $postNumber) {
                        $errors["posts.{$index}.quote.post_number"][] = 'A quote must refer to an earlier post in this thread.';
                    } elseif (! is_string($quoteText) || preg_match('/\A\s*\z/u', $quoteText) === 1) {
                        $errors["posts.{$index}.quote.text"][] = 'Quoted text must be non-empty.';
                    } else {
                        $sourceBody = $posts[$quoteTarget - 1]['body'] ?? null;

                        if (! is_string($sourceBody) || mb_strpos($sourceBody, $quoteText, 0, 'UTF-8') === false) {
                            $errors["posts.{$index}.quote.text"][] = 'Quoted text must exactly match a contiguous part of the referenced post.';
                        }
                    }
                }
            }

            if ($index === 0 && $replyTarget !== null) {
                $errors["posts.{$index}.reply_to_post_number"][] = 'The opening post cannot reply to another post.';
            }

            if ($index === 0 && $quote !== null) {
                $errors["posts.{$index}.quote"][] = 'The opening post cannot reply to or quote another post.';
            }

            $postedAt = $this->parseTimestamp($post['posted_at'] ?? null);

            if ($postedAt === null) {
                $errors["posts.{$index}.posted_at"][] = 'The post time must use a valid timestamp with a numeric timezone offset.';

                continue;
            }

            if ($index === 0 && $startedAt !== null && $postedAt < $startedAt) {
                $errors["posts.{$index}.posted_at"][] = 'The opening post cannot be earlier than the thread start.';
            }

            if ($previousPostAt !== null && $postedAt < $previousPostAt) {
                $errors["posts.{$index}.posted_at"][] = 'Posts must be in chronological order.';
            }

            $previousPostAt = $postedAt;
        }

        return $errors;
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
            $post['reply_to_post_number'] = $this->normalizePostNumber($post['reply_to_post_number'] ?? null);

            $quote = $post['quote'] ?? null;
            $quoteTarget = is_array($quote) ? $this->normalizePostNumber($quote['post_number'] ?? null) : null;
            $quoteText = is_array($quote) && is_string($quote['text'] ?? null) ? $quote['text'] : null;
            $post['quote'] = $quoteTarget === null && ($quoteText === null || $quoteText === '')
                ? null
                : ['post_number' => $quoteTarget, 'text' => $quoteText];
        }
        unset($post);

        $submittedContent['posts'] = $editedPosts;

        return $submittedContent;
    }

    private function normalizePostNumber(mixed $postNumber): mixed
    {
        if ($postNumber === null || $postNumber === '') {
            return null;
        }

        if (is_int($postNumber)) {
            return $postNumber;
        }

        if (is_string($postNumber) && preg_match('/\A-?\d+\z/', $postNumber) === 1) {
            return (int) $postNumber;
        }

        return $postNumber;
    }

    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-](?:[01]\d|2[0-3]):[0-5]\d\z/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!'.self::TIMESTAMP_FORMAT, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
