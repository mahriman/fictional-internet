<?php

namespace App\ContentTypes;

use App\ContentTypes\Contracts\ContentTypeDefinition;
use App\ContentTypes\Contracts\ContinuableContentType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ContentContinuationComposer
{
    /**
     * Return the strict provider-facing shape for new entries only.
     *
     * @param  array<string, mixed>  $sourceContent
     * @return array<string, mixed>
     */
    public function proposalSchema(ContentTypeDefinition $definition, array $sourceContent): array
    {
        $sourceContent = $this->validateDocument($definition, $sourceContent, 'source');

        return $this->buildProposalSchema($definition, $sourceContent);
    }

    /**
     * @param  array<string, mixed>  $sourceContent
     * @return array<string, mixed>
     */
    private function buildProposalSchema(ContentTypeDefinition $definition, array $sourceContent): array
    {
        $continuation = $this->continuationType($definition);
        $collection = $continuation->continuationCollectionField();
        $numberField = $continuation->continuationNumberField();
        $collectionSchema = $definition->outputSchema()['properties'][$collection] ?? null;
        $sourceEntries = $sourceContent[$collection] ?? null;

        if (! is_array($collectionSchema)
            || ! is_array($collectionSchema['items'] ?? null)
            || ! is_array($collectionSchema['items']['properties'] ?? null)
            || ! is_array($sourceEntries)
            || ! array_is_list($sourceEntries)) {
            throw new InvalidArgumentException('The content type does not have a usable continuation structure.');
        }

        $maximumSourceEntries = $collectionSchema['maxItems'] ?? null;

        if (! is_int($maximumSourceEntries) || $maximumSourceEntries < 1) {
            throw new InvalidArgumentException('The content type does not define a valid continuation limit.');
        }

        $maximumEntries = $maximumSourceEntries - count($sourceEntries);

        if ($maximumEntries < 1) {
            throw ValidationException::withMessages([
                $collection => ['This discussion has reached its maximum number of entries and cannot be continued.'],
            ]);
        }

        $entrySchema = $collectionSchema['items'];
        unset($entrySchema['properties'][$numberField]);
        $entrySchema['required'] = array_values(array_diff($entrySchema['required'] ?? [], [$numberField]));

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'entries' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => $maximumEntries,
                    'items' => $entrySchema,
                ],
            ],
            'required' => ['entries'],
        ];
    }

    /**
     * Compose proposed entries onto an immutable source document and validate the complete result.
     *
     * The proposal is an object with a single `entries` list. Entry numbers and all document-level
     * values come from the source or are assigned here; callers cannot submit either.
     *
     * @param  array<string, mixed>  $sourceContent
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function compose(
        ContentTypeDefinition $definition,
        array $sourceContent,
        array $proposal,
    ): array {
        $continuation = $this->continuationType($definition);
        $collection = $continuation->continuationCollectionField();
        $numberField = $continuation->continuationNumberField();

        $sourceContent = $this->validateDocument($definition, $sourceContent, 'source');
        $sourceEntries = $sourceContent[$collection];
        $proposalSchema = $this->buildProposalSchema($definition, $sourceContent);
        $entryProperties = array_keys($proposalSchema['properties']['entries']['items']['properties']);
        $availableEntries = $proposalSchema['properties']['entries']['maxItems'];
        $proposalRules = [
            'continuation' => ['required', 'array:entries'],
            'continuation.entries' => ['required', 'array', 'list', 'min:1', 'max:'.$availableEntries],
            'continuation.entries.*' => ['required', 'array:'.implode(',', $entryProperties)],
        ];

        foreach ($definition->validationRules() as $attribute => $rules) {
            $prefix = $collection.'.*.';

            if (! str_starts_with($attribute, $prefix)) {
                continue;
            }

            $entryField = substr($attribute, strlen($prefix));

            if ($entryField === $numberField || str_starts_with($entryField, $numberField.'.')) {
                continue;
            }

            $proposalRules['continuation.entries.*.'.$entryField] = $rules;
        }

        $validatedProposal = Validator::make(
            ['continuation' => $proposal],
            $proposalRules,
        );

        if ($validatedProposal->fails()) {
            throw ValidationException::withMessages($validatedProposal->errors()->messages());
        }

        $entries = $validatedProposal->validated()['continuation']['entries'];
        $lastEntry = $sourceEntries[array_key_last($sourceEntries)];
        $nextNumber = $lastEntry[$numberField] + 1;
        $numberedEntries = [];

        foreach ($entries as $entry) {
            $entry[$numberField] = $nextNumber++;
            $numberedEntries[] = $entry;
        }

        $combinedContent = $sourceContent;
        $combinedContent[$collection] = [...$sourceEntries, ...$numberedEntries];

        return $this->validateDocument($definition, $combinedContent, 'combined');
    }

    private function continuationType(ContentTypeDefinition $definition): ContinuableContentType
    {
        if (! $definition instanceof ContinuableContentType) {
            throw new InvalidArgumentException('This content type does not support continuation.');
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function validateDocument(
        ContentTypeDefinition $definition,
        array $content,
        string $attributePrefix,
    ): array {
        $schema = $definition->outputSchema();
        $properties = $schema['properties'] ?? null;

        if (($schema['type'] ?? null) !== 'object' || ! is_array($properties) || $properties === []) {
            throw new InvalidArgumentException('The registered content type has an invalid output schema.');
        }

        $rules = [
            $attributePrefix => ['required', 'array:'.implode(',', array_keys($properties))],
        ];

        foreach ($definition->validationRules() as $attribute => $attributeRules) {
            $rules[$attributePrefix.'.'.$attribute] = $attributeRules;
        }

        $validator = Validator::make([$attributePrefix => $content], $rules);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->messages());
        }

        $validatedContent = $validator->validated()[$attributePrefix];
        $semanticErrors = $definition->semanticValidationErrors($validatedContent);

        if ($semanticErrors !== []) {
            $prefixedErrors = [];

            foreach ($semanticErrors as $path => $messages) {
                $prefixedErrors[$attributePrefix.'.'.$path] = $messages;
            }

            throw ValidationException::withMessages($prefixedErrors);
        }

        return $content;
    }
}
