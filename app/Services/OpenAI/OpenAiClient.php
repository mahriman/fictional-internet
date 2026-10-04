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

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new OpenAiException(
                'An OpenAI API key must be supplied for this request.',
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
                diagnosticContext: [
                    'diagnostic_stage' => 'network_transport',
                    'requested_max_output_tokens' => null,
                ],
            );
        }

        if (! $response->successful()) {
            throw new OpenAiException(
                "The OpenAI request failed with HTTP status {$response->status()}.",
                $response->status(),
                diagnosticContext: [
                    'diagnostic_stage' => 'provider_http_error',
                    'http_status' => $response->status(),
                    'requested_max_output_tokens' => null,
                ],
            );
        }

        $data = $response->json();
        $httpStatus = $response->status();

        if (! is_array($data)) {
            throw new OpenAiException(
                'The OpenAI response was malformed.',
                failureKind: OpenAiFailureKind::MalformedResponse,
                diagnosticContext: $this->diagnosticContext('provider_response_shape', $httpStatus),
            );
        }

        $providerStatus = $data['status'] ?? null;
        $safeProviderStatus = $this->safeProviderStatus($providerStatus);
        $usage = $this->usageMetadata($data['usage'] ?? null);
        $responseDiagnostics = $this->diagnosticContext(
            'provider_response_status',
            $httpStatus,
            $safeProviderStatus,
            $usage,
        );

        if (! is_string($providerStatus) || ! in_array($providerStatus, [
            'completed', 'failed', 'cancelled', 'queued', 'in_progress', 'incomplete',
        ], true)) {
            throw new OpenAiException(
                'The OpenAI response status was malformed.',
                failureKind: OpenAiFailureKind::MalformedResponse,
                diagnosticContext: $responseDiagnostics,
            );
        }

        if ($providerStatus === 'incomplete') {
            $reason = data_get($data, 'incomplete_details.reason');
            $responseDiagnostics['diagnostic_stage'] = 'provider_incomplete_response';
            $responseDiagnostics['incomplete_reason'] = $this->safeIncompleteReason($reason);

            throw new OpenAiException(
                'The OpenAI response was incomplete.',
                failureKind: OpenAiFailureKind::IncompleteResponse,
                diagnosticContext: $responseDiagnostics,
            );
        }

        if ($providerStatus !== 'completed') {
            $responseDiagnostics['diagnostic_stage'] = 'provider_response_not_completed';

            throw new OpenAiException(
                'The OpenAI response did not complete successfully.',
                failureKind: OpenAiFailureKind::IncompleteResponse,
                diagnosticContext: $responseDiagnostics,
            );
        }

        if ($usage['malformed']) {
            $responseDiagnostics['diagnostic_stage'] = 'usage_metadata';

            throw new OpenAiException(
                'The OpenAI response usage data was malformed.',
                failureKind: OpenAiFailureKind::MalformedResponse,
                diagnosticContext: $responseDiagnostics,
            );
        }

        $responseId = $data['id'] ?? null;
        $actualModel = $data['model'] ?? null;
        $output = $data['output'] ?? null;

        if (! is_string($responseId) || $responseId === '' || ! is_string($actualModel) || $actualModel === '' || ! is_array($output)) {
            $responseDiagnostics['diagnostic_stage'] = 'provider_response_shape';

            throw new OpenAiException(
                'The OpenAI response was malformed.',
                failureKind: OpenAiFailureKind::MalformedResponse,
                diagnosticContext: $responseDiagnostics,
            );
        }

        $text = $this->extractOutputText($output, $responseDiagnostics);

        if ($text === null || trim($text) === '') {
            $responseDiagnostics['diagnostic_stage'] = 'provider_output_text_missing';

            throw new OpenAiException(
                'The OpenAI response did not contain generated text.',
                failureKind: OpenAiFailureKind::MalformedResponse,
                diagnosticContext: $responseDiagnostics,
            );
        }

        return new OpenAiResponseResult(
            text: $text,
            responseId: $responseId,
            model: $actualModel,
            inputTokens: $usage['input_tokens'],
            outputTokens: $usage['output_tokens'],
            totalTokens: $usage['total_tokens'],
            providerStatus: $providerStatus,
            reasoningTokens: $usage['reasoning_tokens'],
            httpStatus: $httpStatus,
        );
    }

    /**
     * @param  array<mixed>  $output
     */
    private function extractOutputText(array $output, array $diagnostics): ?string
    {
        $text = '';
        $foundText = false;

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $content = $item['content'] ?? null;

            if (! is_array($content)) {
                $diagnostics['diagnostic_stage'] = 'provider_output_content';

                throw new OpenAiException(
                    'The OpenAI response content was malformed.',
                    failureKind: OpenAiFailureKind::MalformedResponse,
                    diagnosticContext: $diagnostics,
                );
            }

            foreach ($content as $block) {
                if (! is_array($block)) {
                    continue;
                }

                if (($block['type'] ?? null) === 'refusal') {
                    $diagnostics['diagnostic_stage'] = 'provider_refusal';

                    throw new OpenAiException(
                        'The OpenAI response was refused.',
                        failureKind: OpenAiFailureKind::Refusal,
                        diagnosticContext: $diagnostics,
                    );
                }

                if (($block['type'] ?? null) !== 'output_text') {
                    continue;
                }

                if (! is_string($block['text'] ?? null)) {
                    $diagnostics['diagnostic_stage'] = 'provider_output_text_shape';

                    throw new OpenAiException(
                        'The OpenAI response text was malformed.',
                        failureKind: OpenAiFailureKind::MalformedResponse,
                        diagnosticContext: $diagnostics,
                    );
                }

                $text .= $block['text'];
                $foundText = true;
            }
        }

        return $foundText ? $text : null;
    }

    /**
     * @return array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null, reasoning_tokens: int|null, malformed: bool}
     */
    private function usageMetadata(mixed $usage): array
    {
        $metadata = [
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'reasoning_tokens' => null,
            'malformed' => false,
        ];

        if ($usage === null) {
            return $metadata;
        }

        if (! is_array($usage)) {
            $metadata['malformed'] = true;

            return $metadata;
        }

        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
            $metadata[$key] = $this->safeTokenCount($usage, $key, $metadata['malformed']);
        }

        $outputDetails = $usage['output_tokens_details'] ?? null;

        if ($outputDetails !== null && ! is_array($outputDetails)) {
            $metadata['malformed'] = true;
        } elseif (is_array($outputDetails)) {
            $metadata['reasoning_tokens'] = $this->safeTokenCount($outputDetails, 'reasoning_tokens', $metadata['malformed']);
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function safeTokenCount(array $source, string $key, bool &$malformed): ?int
    {
        $count = $source[$key] ?? null;

        if ($count === null) {
            return null;
        }

        if (! is_int($count) || $count < 0) {
            $malformed = true;

            return null;
        }

        return $count;
    }

    /**
     * @param  array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null, reasoning_tokens: int|null, malformed: bool}|null  $usage
     * @return array<string, int|string|bool|null>
     */
    private function diagnosticContext(
        string $stage,
        ?int $httpStatus = null,
        ?string $providerStatus = null,
        ?array $usage = null,
    ): array {
        return [
            'diagnostic_stage' => $stage,
            'http_status' => $httpStatus,
            'provider_status' => $providerStatus,
            'requested_max_output_tokens' => null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'reasoning_tokens' => $usage['reasoning_tokens'] ?? null,
            'usage_metadata_malformed' => $usage['malformed'] ?? null,
        ];
    }

    private function safeProviderStatus(mixed $status): ?string
    {
        if (! is_string($status)) {
            return null;
        }

        return in_array($status, ['completed', 'failed', 'cancelled', 'queued', 'in_progress', 'incomplete'], true)
            ? $status
            : 'unexpected';
    }

    private function safeIncompleteReason(mixed $reason): ?string
    {
        if (! is_string($reason) || $reason === '') {
            return null;
        }

        return in_array($reason, ['max_output_tokens', 'content_filter'], true) ? $reason : 'other';
    }
}
