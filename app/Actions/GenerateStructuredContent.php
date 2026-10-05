<?php

namespace App\Actions;

use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Contracts\GeneratedContentNormalizer;
use App\Exceptions\StructuredContentGenerationException;
use App\Services\OpenAI\OpenAiClient;
use App\Services\OpenAI\OpenAiResponseResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use JsonException;
use stdClass;

class GenerateStructuredContent
{
    public function __construct(
        private ContentTypeRegistry $contentTypes,
        private OpenAiClient $openAiClient,
    ) {}

    public function handle(string $contentType, string $prompt, ?string $apiKey = null): StructuredContentGenerationResult
    {
        $definition = $this->contentTypes->get($contentType);

        if (trim($prompt) === '') {
            throw new InvalidArgumentException('A generation prompt is required.');
        }

        $schema = $definition->outputSchema();
        $properties = $schema['properties'] ?? null;

        if (($schema['type'] ?? null) !== 'object' || ! is_array($properties) || $properties === []) {
            throw new StructuredContentGenerationException(
                'The registered content type has an invalid output schema.',
                diagnosticCategory: 'schema_definition',
            );
        }

        $generated = $this->requestStructuredObject(
            $contentType,
            $definition->promptInstructions(),
            $prompt,
            $schema,
            $apiKey,
        );
        $response = $generated->response;
        $content = $generated->content;

        [$entryCollection, $entryCount] = $this->structuredEntryCount($content, $schema);

        $validationRules = [
            'content' => ['required', 'array:'.implode(',', array_keys($properties))],
        ];

        foreach ($definition->validationRules() as $attribute => $rules) {
            $validationRules["content.{$attribute}"] = $rules;
        }

        $validator = Validator::make(['content' => $content], $validationRules);

        $validationFailed = $validator->fails();
        $normalizationDiagnostics = null;

        if (! $validationFailed && $definition instanceof GeneratedContentNormalizer) {
            $normalization = $definition->normalizeGeneratedContent($validator->validated()['content']);
            $content = $normalization['content'];
            $normalizationDiagnostics = [
                'quotes_preserved' => $normalization['quotes_preserved'],
                'quotes_normalized' => $normalization['quotes_normalized'],
                'quotes_dropped' => $normalization['quotes_dropped'],
            ];
            $validator = Validator::make(['content' => $content], $validationRules);
            $validationFailed = $validator->fails();
        }

        $semanticErrors = [];

        if (! $validationFailed) {
            $semanticErrors = $definition->semanticValidationErrors($validator->validated()['content']);

            foreach ($semanticErrors as $attribute => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add('content.'.$attribute, $message);
                }
            }
        }

        if ($validationFailed || $validator->errors()->isNotEmpty()) {
            $fieldPaths = array_values(array_filter(
                $validator->errors()->keys(),
                static fn (string $path): bool => strlen($path) <= 160
                    && preg_match('/\A[a-zA-Z0-9_.\[\]-]+\z/', $path) === 1,
            ));

            throw new StructuredContentGenerationException(
                'The generated content failed validation.',
                diagnosticCategory: $validationFailed ? 'schema_validation' : 'semantic_validation',
                fieldPaths: $fieldPaths,
                diagnosticCodes: $validationFailed
                    ? ['content_schema_validation_failed']
                    : $this->semanticDiagnosticCodes($semanticErrors),
                diagnosticContext: $this->responseDiagnosticContext(
                    $response,
                    $validationFailed ? 'schema_validation' : 'semantic_validation',
                    $entryCollection,
                    $entryCount,
                    $normalizationDiagnostics,
                ),
            );
        }

        if ($normalizationDiagnostics !== null) {
            Log::info('Generated discussion quote normalization completed.', [
                'content_type' => $contentType,
                ...$normalizationDiagnostics,
            ]);
        }

        return new StructuredContentGenerationResult(
            content: $validator->validated()['content'],
            response: $response,
        );
    }

    /**
     * Request a strict structured object whose domain validation is performed by a caller-specific composer.
     *
     * @param  array<string, mixed>  $proposalSchema
     */
    public function handleProposal(
        string $contentType,
        string $instructions,
        string $input,
        array $proposalSchema,
        ?string $apiKey = null,
    ): StructuredContentGenerationResult {
        $this->contentTypes->get($contentType);

        if (($proposalSchema['type'] ?? null) !== 'object'
            || ! is_array($proposalSchema['properties'] ?? null)
            || $proposalSchema['properties'] === []) {
            throw new StructuredContentGenerationException(
                'The continuation proposal schema is invalid.',
                diagnosticCategory: 'schema_definition',
                diagnosticCodes: ['continuation_schema_invalid'],
                diagnosticContext: ['operation' => 'continuation', 'content_type' => $contentType],
            );
        }

        return $this->requestStructuredObject($contentType, $instructions, $input, $proposalSchema, $apiKey);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function requestStructuredObject(
        string $contentType,
        string $instructions,
        string $input,
        array $schema,
        ?string $apiKey,
    ): StructuredContentGenerationResult {
        $response = $this->openAiClient->createResponse(
            instructions: $instructions,
            input: $input,
            apiKey: $apiKey,
            outputSchema: $schema,
        );

        try {
            $decodedObject = json_decode($response->text, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StructuredContentGenerationException(
                'The generated response was not valid JSON.',
                diagnosticCategory: 'json_decode',
                previous: $exception,
                diagnosticCodes: ['invalid_json'],
                diagnosticContext: $this->responseDiagnosticContext($response, 'json_decode', contentType: $contentType),
            );
        }

        if (! $decodedObject instanceof stdClass) {
            throw new StructuredContentGenerationException(
                'The generated response must be a JSON object.',
                diagnosticCategory: 'json_root',
                diagnosticCodes: ['unexpected_json_root'],
                diagnosticContext: $this->responseDiagnosticContext($response, 'json_root', contentType: $contentType),
            );
        }

        $content = json_decode($response->text, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($content)) {
            throw new StructuredContentGenerationException(
                'The generated response must be a JSON object.',
                diagnosticCategory: 'json_root',
                diagnosticCodes: ['unexpected_json_root'],
                diagnosticContext: $this->responseDiagnosticContext($response, 'json_root', contentType: $contentType),
            );
        }

        return new StructuredContentGenerationResult($content, $response);
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $schema
     * @return array{0: string|null, 1: int|null}
     */
    private function structuredEntryCount(array $content, array $schema): array
    {
        $collections = [];

        foreach ($schema['properties'] ?? [] as $field => $property) {
            if (($property['type'] ?? null) !== 'array' || ($property['items']['type'] ?? null) !== 'object') {
                continue;
            }

            $value = $content[$field] ?? null;

            if (is_array($value) && array_is_list($value)) {
                $collections[$field] = count($value);
            }
        }

        if (count($collections) !== 1) {
            return [null, null];
        }

        $field = array_key_first($collections);

        return [$field, $collections[$field]];
    }

    /**
     * @param  array<string, list<string>>  $semanticErrors
     * @return list<string>
     */
    private function semanticDiagnosticCodes(array $semanticErrors): array
    {
        $codes = ['semantic_validation_failed'];

        foreach (array_keys($semanticErrors) as $path) {
            $codes[] = match (true) {
                str_contains($path, '.quote') => 'discussion_quote_invalid',
                str_contains($path, 'reply_to_') => 'discussion_reply_invalid',
                preg_match('/(?:^|\.)(?:post_number|message_number)\z/', $path) === 1 => 'discussion_numbering_invalid',
                str_ends_with($path, '_at') => 'discussion_timestamp_invalid',
                default => 'semantic_constraint_failed',
            };
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    private function responseDiagnosticContext(
        OpenAiResponseResult $response,
        string $stage,
        ?string $entryCollection = null,
        ?int $entryCount = null,
        ?array $normalizationDiagnostics = null,
        ?string $contentType = null,
    ): array {
        return array_filter([
            'diagnostic_stage' => $stage,
            'content_type' => $contentType,
            'provider_status' => $response->providerStatus,
            'http_status' => $response->httpStatus,
            'requested_max_output_tokens' => $response->requestedMaxOutputTokens,
            'output_tokens' => $response->outputTokens,
            'reasoning_tokens' => $response->reasoningTokens,
            'entry_collection' => $entryCollection,
            'entry_count' => $entryCount,
            ...($normalizationDiagnostics ?? []),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
