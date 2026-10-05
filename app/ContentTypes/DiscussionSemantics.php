<?php

namespace App\ContentTypes;

use DateTimeImmutable;

class DiscussionSemantics
{
    public const TIMESTAMP_FORMAT = 'Y-m-d\\TH:i:sP';

    /**
     * @param  array<string, mixed>  $content
     * @param  array{collection: string, number: string, body: string, quote: string, quote_number: string, quote_text: string}  $fields
     * @return array{
     *     content: array<string, mixed>,
     *     quotes_preserved: int,
     *     quotes_normalized: int,
     *     quotes_dropped: int
     * }
     */
    public static function normalizeGeneratedQuotes(array $content, array $fields, int $firstIndexToNormalize = 0): array
    {
        $counts = [
            'quotes_preserved' => 0,
            'quotes_normalized' => 0,
            'quotes_dropped' => 0,
        ];
        $items = $content[$fields['collection']] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            return ['content' => $content, ...$counts];
        }

        $items = &$content[$fields['collection']];

        foreach ($items as $index => &$item) {
            if ($index < $firstIndexToNormalize) {
                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $quote = $item[$fields['quote']] ?? null;

            if ($quote === null) {
                continue;
            }

            if (! is_array($quote)
                || count($quote) !== 2
                || ! array_key_exists($fields['quote_number'], $quote)
                || ! array_key_exists($fields['quote_text'], $quote)) {
                continue;
            }

            $target = $quote[$fields['quote_number']];
            $itemNumber = $item[$fields['number']] ?? null;

            if (! is_int($target)
                || ! is_int($itemNumber)
                || $itemNumber !== $index + 1
                || $target < 1
                || $target >= $itemNumber
                || $target > $index) {
                continue;
            }

            $sourceItem = $items[$target - 1] ?? null;

            if (! is_array($sourceItem)
                || ($sourceItem[$fields['number']] ?? null) !== $target
                || ! is_string($sourceItem[$fields['body']] ?? null)
                || ! is_string($quote[$fields['quote_text']])) {
                continue;
            }

            $sourceBody = $sourceItem[$fields['body']];
            $quoteText = $quote[$fields['quote_text']];

            if (preg_match('/\A\s*\z/u', $quoteText) !== 1 && self::containsExactSubstring($sourceBody, $quoteText)) {
                $counts['quotes_preserved']++;

                continue;
            }

            $recoveredText = self::recoverExactQuote($sourceBody, $quoteText);

            if ($recoveredText !== null) {
                $item[$fields['quote']][$fields['quote_text']] = $recoveredText;
                $counts['quotes_normalized']++;

                continue;
            }

            $item[$fields['quote']] = null;
            $counts['quotes_dropped']++;
        }
        unset($item);

        return ['content' => $content, ...$counts];
    }

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

    private static function recoverExactQuote(string $sourceBody, string $quoteText): ?string
    {
        $trimmedQuote = self::trimUnicodeWhitespace($quoteText);
        $candidates = [$trimmedQuote];
        $quoteMarks = [
            '"' => '"',
            "'" => "'",
            '“' => '”',
            '‘' => '’',
            '«' => '»',
            '‹' => '›',
        ];

        foreach ($quoteMarks as $openingMark => $closingMark) {
            if (str_starts_with($trimmedQuote, $openingMark) && str_ends_with($trimmedQuote, $closingMark)) {
                $candidates[] = self::trimUnicodeWhitespace(mb_substr(
                    $trimmedQuote,
                    mb_strlen($openingMark, 'UTF-8'),
                    mb_strlen($trimmedQuote, 'UTF-8') - mb_strlen($openingMark, 'UTF-8') - mb_strlen($closingMark, 'UTF-8'),
                    'UTF-8',
                ));
                break;
            }
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if ($candidate !== '' && str_contains($candidate, '…')) {
                $candidates[] = str_replace('…', '...', $candidate);
            } elseif ($candidate !== '' && str_contains($candidate, '...')) {
                $candidates[] = str_replace('...', '…', $candidate);
            }
        }

        $matches = [];

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate !== '' && self::containsExactSubstring($sourceBody, $candidate)) {
                $matches[$candidate] = true;
            }
        }

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    private static function containsExactSubstring(string $source, string $candidate): bool
    {
        return mb_strpos($source, $candidate, 0, 'UTF-8') !== false;
    }

    private static function trimUnicodeWhitespace(string $value): string
    {
        return preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value) ?? $value;
    }
}
