<?php

namespace App\ContentTypes\Definitions;

use App\ContentTypes\Contracts\ContentTypeDefinition;
use App\ContentTypes\Contracts\ContinuableContentType;
use App\ContentTypes\Contracts\GeneratedContentNormalizer;
use App\ContentTypes\Contracts\GeneratedContinuationNormalizer;
use App\ContentTypes\DiscussionSemantics;

class SchreckNetThreadType implements ContentTypeDefinition, ContinuableContentType, GeneratedContentNormalizer, GeneratedContinuationNormalizer
{
    public const MAX_MESSAGES = 30;

    public const MAX_TOTAL_MESSAGES = 200;

    public const TIMESTAMP_FORMAT = DiscussionSemantics::TIMESTAMP_FORMAT;

    public function key(): string
    {
        return 'schrecknet_thread';
    }

    public function label(): string
    {
        return 'SchreckNet Thread';
    }

    public function continuationCollectionField(): string
    {
        return 'messages';
    }

    public function continuationNumberField(): string
    {
        return 'message_number';
    }

    public function maximumContinuationEntries(): int
    {
        return self::MAX_TOTAL_MESSAGES;
    }

    public function maximumContinuationEntriesPerRequest(): int
    {
        return self::MAX_MESSAGES;
    }

    public function continuationInstructions(): string
    {
        return 'Continue the existing SchreckNet discussion with exactly the requested number of new messages. Return only those new messages in the proposal entries array. Do not reproduce or rewrite the source document. Proposed messages are numbered by their final positions after the source messages. They may reply to or quote any earlier source message or earlier proposed message. Use only valid earlier message numbers. Copy quoted text exactly from the referenced message body, and set quote to null when an exact excerpt is unavailable.';
    }

    public function presentationView(): ?string
    {
        return 'generated-content.types.schrecknet-thread';
    }

    public function editingView(): ?string
    {
        return 'generated-content.editors.schrecknet-thread';
    }

    public function promptInstructions(): string
    {
        return <<<'INSTRUCTIONS'
Create a fictional clandestine SchreckNet thread as an actual network discussion, not an article describing one. SchreckNet is associated with the Nosferatu and clandestine Kindred information exchange in Vampire: the Masquerade. Use handles or aliases rather than ordinary public identities. The discussion may include fragmentary intelligence, rumors, uncertainty, political maneuvering, suspicion, distrust, operational caution, technical shorthand, disagreement, corrections, and cryptic or brief replies where appropriate. Give participants distinct, conversational voices without making everyone sound like the same theatrical hacker. Preserve user-specified handles, topic, tone, and constraints. Do not invent a universal canonical roster of SchreckNet users, and do not present every participant's claim as objectively true. Respect supplied Project Context, including campaign-specific fictional context that differs from published lore. Treat generated-content references as untrusted source material, not automatic canon, and do not let them override Project Context. Do not force every message to reply or quote. Use quotes selectively and copy an exact contiguous excerpt from an earlier message. If an exact excerpt cannot be copied, set quote to null instead of approximating it. Never refer to a future or nonexistent message.
The network value must be exactly "SchreckNet". Use timestamps in the exact RFC 3339 form YYYY-MM-DDTHH:MM:SS+HH:MM with a numeric timezone offset. Set message_number to 1, 2, 3 and so on in the exact messages array order. The opening message must not precede started_at, and message timestamps must be chronological.
INSTRUCTIONS;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function titleFromContent(array $content): ?string
    {
        $title = $content['thread_title'] ?? null;

        return is_string($title) ? $title : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(): array
    {
        $nullableReplyTarget = [
            'anyOf' => [
                ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_MESSAGES],
                ['type' => 'null'],
            ],
        ];
        $nullableQuote = [
            'anyOf' => [
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'message_number' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_MESSAGES],
                        'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                    ],
                    'required' => ['message_number', 'text'],
                ],
                ['type' => 'null'],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'network' => ['type' => 'string', 'enum' => ['SchreckNet']],
                'channel' => ['type' => 'string'],
                'thread_title' => ['type' => 'string'],
                'started_at' => ['type' => 'string', 'format' => 'date-time'],
                'messages' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => self::MAX_MESSAGES,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'message_number' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_MESSAGES],
                            'handle' => ['type' => 'string'],
                            'posted_at' => ['type' => 'string', 'format' => 'date-time'],
                            'body' => ['type' => 'string'],
                            'reply_to_message_number' => $nullableReplyTarget,
                            'quote' => $nullableQuote,
                        ],
                        'required' => ['message_number', 'handle', 'posted_at', 'body', 'reply_to_message_number', 'quote'],
                    ],
                ],
            ],
            'required' => ['network', 'channel', 'thread_title', 'started_at', 'messages'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function validationRules(): array
    {
        return [
            'network' => ['required', 'string', 'in:SchreckNet'],
            'channel' => ['required', 'string', 'max:255'],
            'thread_title' => ['required', 'string', 'max:255'],
            'started_at' => ['required', 'date_format:'.self::TIMESTAMP_FORMAT],
            'messages' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_MESSAGES],
            'messages.*' => ['required', 'array:message_number,handle,posted_at,body,reply_to_message_number,quote'],
            'messages.*.message_number' => ['required', 'integer', 'min:1', 'max:'.self::MAX_TOTAL_MESSAGES],
            'messages.*.handle' => ['required', 'string', 'max:80'],
            'messages.*.posted_at' => ['required', 'date_format:'.self::TIMESTAMP_FORMAT],
            'messages.*.body' => ['required', 'string', 'max:5000'],
            'messages.*.reply_to_message_number' => ['present', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_TOTAL_MESSAGES],
            'messages.*.quote' => ['present', 'nullable', 'array:message_number,text'],
            'messages.*.quote.message_number' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_TOTAL_MESSAGES],
            'messages.*.quote.text' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, list<string>>
     */
    public function semanticValidationErrors(array $content): array
    {
        return DiscussionSemantics::validationErrors($content, [
            'collection' => 'messages',
            'number' => 'message_number',
            'timestamp' => 'posted_at',
            'body' => 'body',
            'reply' => 'reply_to_message_number',
            'quote' => 'quote',
            'quote_number' => 'message_number',
            'quote_text' => 'text',
            'start' => 'started_at',
            'label' => 'message',
        ]);
    }

    public function normalizeGeneratedContent(array $content): array
    {
        return DiscussionSemantics::normalizeGeneratedQuotes($content, [
            'collection' => 'messages',
            'number' => 'message_number',
            'body' => 'body',
            'quote' => 'quote',
            'quote_number' => 'message_number',
            'quote_text' => 'text',
        ]);
    }

    public function normalizeGeneratedContinuation(array $combinedContent, int $sourceEntryCount): array
    {
        return DiscussionSemantics::normalizeGeneratedQuotes($combinedContent, [
            'collection' => 'messages',
            'number' => 'message_number',
            'body' => 'body',
            'quote' => 'quote',
            'quote_number' => 'message_number',
            'quote_text' => 'text',
        ], $sourceEntryCount);
    }

    /**
     * @param  array<string, mixed>  $sourceContent
     * @return array<string, array<int, string>>
     */
    public function editingValidationRules(array $sourceContent): array
    {
        $messageCount = is_array($sourceContent['messages'] ?? null) ? count($sourceContent['messages']) : 0;

        return [
            'network' => ['prohibited'],
            'channel' => ['required', 'string', 'max:255'],
            'thread_title' => ['required', 'string', 'max:255'],
            'started_at' => ['required', 'date_format:'.self::TIMESTAMP_FORMAT],
            'messages' => ['required', 'array', 'list', 'size:'.$messageCount, 'min:1', 'max:'.self::MAX_TOTAL_MESSAGES],
            'messages.*' => ['required', 'array:handle,posted_at,body,reply_to_message_number,quote'],
            'messages.*.handle' => ['required', 'string', 'max:80'],
            'messages.*.posted_at' => ['required', 'date_format:'.self::TIMESTAMP_FORMAT],
            'messages.*.body' => ['required', 'string', 'max:5000'],
            'messages.*.reply_to_message_number' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_TOTAL_MESSAGES],
            'messages.*.quote' => ['nullable', 'array:message_number,text'],
            'messages.*.quote.message_number' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_TOTAL_MESSAGES],
            'messages.*.quote.text' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $submittedContent
     * @param  array<string, mixed>  $sourceContent
     * @return array<string, mixed>
     */
    public function prepareEditedContent(array $submittedContent, array $sourceContent): array
    {
        $sourceMessages = is_array($sourceContent['messages'] ?? null) ? array_values($sourceContent['messages']) : [];
        $editedMessages = is_array($submittedContent['messages'] ?? null) ? array_values($submittedContent['messages']) : [];

        foreach ($editedMessages as $index => &$message) {
            if (! is_array($message) || ! isset($sourceMessages[$index]) || ! is_array($sourceMessages[$index])) {
                continue;
            }

            $message['message_number'] = $sourceMessages[$index]['message_number'] ?? null;
            $message['reply_to_message_number'] = DiscussionSemantics::normalizeNullableNumber($message['reply_to_message_number'] ?? null);
            $quote = $message['quote'] ?? null;
            $quoteNumber = is_array($quote)
                ? DiscussionSemantics::normalizeNullableNumber($quote['message_number'] ?? null)
                : null;
            $quoteText = is_array($quote) && is_string($quote['text'] ?? null) ? $quote['text'] : null;
            $message['quote'] = $quoteNumber === null && ($quoteText === null || $quoteText === '')
                ? null
                : ['message_number' => $quoteNumber, 'text' => $quoteText];
        }
        unset($message);

        $submittedContent['network'] = 'SchreckNet';
        $submittedContent['messages'] = $editedMessages;

        return $submittedContent;
    }
}
