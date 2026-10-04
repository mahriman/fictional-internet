<?php

namespace App\Services\OpenAI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class OpenAiClient
{
    private const string RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';

    private const string STRUCTURED_OUTPUT_NAME = 'generated_content';

    /**
     * @param  array<string, mixed>|null  $outputSchema
     */
    public function createResponse(
        string $instructions,
        string $input,
        ?string $apiKey = null,
        ?array $outputSchema = null,
    ): OpenAiResponseResult {
        if (trim($instructions) === '' || trim($input) === '') {
            throw new InvalidArgumentException('Instructions and input must not be empty.');
        }

        $apiKey ??= config('services.openai.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new OpenAiException(
                'The OpenAI API key is not configured.',
                failureKind: OpenAiFailureKind::Configuration,
            );
        }

        $model = config('services.openai.model');
        $timeout = (int) config('services.openai.timeout', 30);

        if (! is_string($model) || trim($model) === '' || $timeout < 1) {
            throw new OpenAiException(
                'The OpenAI model and a positive timeout must be configured.',
                failureKind: OpenAiFailureKind::Configuration,
            );
        }

        $requestData = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => $input,
            'store' => false,
            'stream' => false,
        ];

        if ($outputSchema !== null) {
            $requestData['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::STRUCTURED_OUTPUT_NAME,
                    'strict' => true,
                    'schema' => $outputSchema,
                ],
            ];
        }

        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->timeout($timeout)
                ->connectTimeout(min($timeout, 10))
                ->post(self::RESPONSES_ENDPOINT, $requestData);
        } catch (ConnectionException $exception) {
            throw new OpenAiException(
                'The OpenAI request failed due to a network error.',
                previous: $exception,
                failureKind: OpenAiFailureKind::Network,
            );
        }

        if (! $response->successful()) {
            throw new OpenAiException(
                "The OpenAI request failed with HTTP status {$response->status()}.",
                $response->status(),
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new OpenAiException('The OpenAI response was malformed.', failureKind: OpenAiFailureKind::MalformedResponse);
        }

        if (($data['status'] ?? null) === 'incomplete') {
            throw new OpenAiException('The OpenAI response was incomplete.', failureKind: OpenAiFailureKind::IncompleteResponse);
        }

        if (($data['status'] ?? null) !== 'completed') {
            throw new OpenAiException('The OpenAI response did not complete successfully.', failureKind: OpenAiFailureKind::IncompleteResponse);
        }

        $responseId = $data['id'] ?? null;
        $actualModel = $data['model'] ?? null;
        $output = $data['output'] ?? null;

        if (! is_string($responseId) || $responseId === '' || ! is_string($actualModel) || $actualModel === '' || ! is_array($output)) {
            throw new OpenAiException('The OpenAI response was malformed.', failureKind: OpenAiFailureKind::MalformedResponse);
        }

        $text = $this->extractOutputText($output);

        if ($text === null || trim($text) === '') {
            throw new OpenAiException('The OpenAI response did not contain generated text.', failureKind: OpenAiFailureKind::MalformedResponse);
        }

        $usage = $data['usage'] ?? null;

        if ($usage !== null && ! is_array($usage)) {
            throw new OpenAiException('The OpenAI response usage data was malformed.', failureKind: OpenAiFailureKind::MalformedResponse);
        }

        return new OpenAiResponseResult(
            text: $text,
            responseId: $responseId,
            model: $actualModel,
            inputTokens: $this->tokenCount($usage, 'input_tokens'),
            outputTokens: $this->tokenCount($usage, 'output_tokens'),
            totalTokens: $this->tokenCount($usage, 'total_tokens'),
        );
    }

    /**
     * @param  array<mixed>  $output
     */
    private function extractOutputText(array $output): ?string
    {
        $text = '';
        $foundText = false;

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $content = $item['content'] ?? null;

            if (! is_array($content)) {
                throw new OpenAiException('The OpenAI response content was malformed.', failureKind: OpenAiFailureKind::MalformedResponse);
            }

            foreach ($content as $block) {
                if (! is_array($block)) {
                    continue;
                }

                if (($block['type'] ?? null) === 'refusal') {
                    throw new OpenAiException('The OpenAI response was refused.', failureKind: OpenAiFailureKind::Refusal);
                }

                if (($block['type'] ?? null) !== 'output_text') {
                    continue;
                }

                if (! is_string($block['text'] ?? null)) {
                    throw new OpenAiException('The OpenAI response text was malformed.', failureKind: OpenAiFailureKind::MalformedResponse);
                }

                $text .= $block['text'];
                $foundText = true;
            }
        }

        return $foundText ? $text : null;
    }

    /**
     * @param  array<string, mixed>|null  $usage
     */
    private function tokenCount(?array $usage, string $key): ?int
    {
        $count = $usage[$key] ?? null;

        if ($count === null) {
            return null;
        }

        if (! is_int($count) || $count < 0) {
            throw new OpenAiException('The OpenAI response usage data was malformed.', failureKind: OpenAiFailureKind::MalformedResponse);
        }

        return $count;
    }
}
