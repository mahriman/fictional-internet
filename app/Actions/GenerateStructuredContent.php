<?php

namespace App\Actions;

use App\ContentTypes\ContentTypeRegistry;
use App\Exceptions\StructuredContentGenerationException;
use App\Services\OpenAI\OpenAiClient;
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
            throw new StructuredContentGenerationException('The registered content type has an invalid output schema.');
        }

        $response = $this->openAiClient->createResponse(
            instructions: $definition->promptInstructions(),
            input: $prompt,
            apiKey: $apiKey,
            outputSchema: $schema,
        );

        try {
            $decodedObject = json_decode($response->text, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StructuredContentGenerationException('The generated response was not valid JSON.', previous: $exception);
        }

        if (! $decodedObject instanceof stdClass) {
            throw new StructuredContentGenerationException('The generated response must be a JSON object.');
        }

        $content = json_decode($response->text, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($content)) {
            throw new StructuredContentGenerationException('The generated response must be a JSON object.');
        }

        $validationRules = [
            'content' => ['required', 'array:'.implode(',', array_keys($properties))],
        ];

        foreach ($definition->validationRules() as $attribute => $rules) {
            $validationRules["content.{$attribute}"] = $rules;
        }

        $validator = Validator::make(['content' => $content], $validationRules);

        $validationFailed = $validator->fails();

        if (! $validationFailed) {
            foreach ($definition->semanticValidationErrors($validator->validated()['content']) as $attribute => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add('content.'.$attribute, $message);
                }
            }
        }

        if ($validationFailed || $validator->errors()->isNotEmpty()) {
            throw new StructuredContentGenerationException('The generated content failed validation.');
        }

        return new StructuredContentGenerationResult(
            content: $validator->validated()['content'],
            response: $response,
        );
    }
}
