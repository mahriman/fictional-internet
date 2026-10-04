<?php

namespace App\ContentTypes;

use DateTimeImmutable;

class DiscussionSemantics
{
    public const TIMESTAMP_FORMAT = 'Y-m-d\\TH:i:sP';

    /**
     * @param  array<string, mixed>  $content
     * @param  array{collection: string, number: string, timestamp: string, body: string, reply: string, quote: string, quote_number: string, quote_text: string, start: string, label: string}  $fields
     * @return array<string, list<string>>
     */
    public static function validationErrors(array $content, array $fields): array
    {
        $errors = [];
        $startedAt = self::parseTimestamp($content[$fields['start']] ?? null);
        $previousItemAt = null;
        $items = is_array($content[$fields['collection']] ?? null)
            ? array_values($content[$fields['collection']])
            : [];
        $label = $fields['label'];
        $capitalizedLabel = ucfirst($label);

        if ($startedAt === null) {
            $errors[$fields['start']][] = 'The thread start time must use a valid timestamp with a numeric timezone offset.';
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $path = $fields['collection'].'.'.$index;
            $itemNumber = $item[$fields['number']] ?? null;
            $replyTarget = $item[$fields['reply']] ?? null;

            if ($itemNumber !== $index + 1) {
                $errors[$path.'.'.$fields['number']][] = "{$capitalizedLabel} numbers must start at 1 and increase by one in displayed order.";
            }

            if ($replyTarget !== null
                && (! is_int($replyTarget) || ! is_int($itemNumber) || $replyTarget < 1 || $replyTarget >= $itemNumber)) {
                $errors[$path.'.'.$fields['reply']][] = "A reply must refer to an earlier {$label} in this thread.";
            }

            $quote = $item[$fields['quote']] ?? null;

            if ($quote !== null) {
                if (! is_array($quote) || array_diff(array_keys($quote), [$fields['quote_number'], $fields['quote_text']]) !== []) {
                    $errors[$path.'.'.$fields['quote']][] = 'The quote must contain only a source message number and exact quoted text.';
                } else {
                    $quoteTarget = $quote[$fields['quote_number']] ?? null;
                    $quoteText = $quote[$fields['quote_text']] ?? null;

                    if (! is_int($quoteTarget) || ! is_int($itemNumber) || $quoteTarget < 1 || $quoteTarget >= $itemNumber) {
                        $errors[$path.'.'.$fields['quote'].'.'.$fields['quote_number']][] = "A quote must refer to an earlier {$label} in this thread.";
                    } elseif (! is_string($quoteText) || preg_match('/\A\s*\z/u', $quoteText) === 1) {
                        $errors[$path.'.'.$fields['quote'].'.'.$fields['quote_text']][] = 'Quoted text must be non-empty.';
                    } else {
                        $sourceBody = $items[$quoteTarget - 1][$fields['body']] ?? null;

                        if (! is_string($sourceBody) || mb_strpos($sourceBody, $quoteText, 0, 'UTF-8') === false) {
                            $errors[$path.'.'.$fields['quote'].'.'.$fields['quote_text']][] = 'Quoted text must exactly match a contiguous part of the referenced message.';
                        }
                    }
                }
            }

            if ($index === 0 && $replyTarget !== null) {
                $errors[$path.'.'.$fields['reply']][] = "The opening {$label} cannot reply to another {$label}.";
            }

            if ($index === 0 && $quote !== null) {
                $errors[$path.'.'.$fields['quote']][] = "The opening {$label} cannot reply to or quote another {$label}.";
            }

            $itemAt = self::parseTimestamp($item[$fields['timestamp']] ?? null);

            if ($itemAt === null) {
                $errors[$path.'.'.$fields['timestamp']][] = "The {$label} time must use a valid timestamp with a numeric timezone offset.";

                continue;
            }

            if ($index === 0 && $startedAt !== null && $itemAt < $startedAt) {
                $errors[$path.'.'.$fields['timestamp']][] = "The opening {$label} cannot be earlier than the thread start.";
            }

            if ($previousItemAt !== null && $itemAt < $previousItemAt) {
                $errors[$path.'.'.$fields['timestamp']][] = "{$capitalizedLabel}s must be in chronological order.";
            }

            $previousItemAt = $itemAt;
        }

        return $errors;
    }

    public static function parseTimestamp(mixed $value): ?DateTimeImmutable
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

    public static function normalizeNullableNumber(mixed $number): mixed
    {
        if ($number === null || $number === '') {
            return null;
        }

        if (is_int($number)) {
            return $number;
        }

        if (is_string($number) && preg_match('/\A-?\d+\z/', $number) === 1) {
            return (int) $number;
        }

        return $number;
    }
}
