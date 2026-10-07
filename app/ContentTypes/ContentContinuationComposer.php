<?php

namespace App\ContentTypes;

use App\ContentTypes\Contracts\ContentTypeDefinition;
use App\ContentTypes\Contracts\ContinuableContentType;
use App\ContentTypes\Contracts\GeneratedContinuationNormalizer;
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
    public function proposalSchema(ContentTypeDefinition $definition, array $sourceContent, ?int $requestedEntryCount = null): array
    {
        $sourceContent = $this->validateDocument($definition, $sourceContent, 'source');

        return $this->buildProposalSchema($definition, $sourceContent, $requestedEntryCount);
    }

    /**
     * Return remaining entry capacity after validating the selected immutable source document.
     *
     * @param  array<string, mixed>  $sourceContent
     */
    public function remainingCapacity(ContentTypeDefinition $definition, array $sourceContent): int
    {
        $sourceContent = $this->validateDocument($definition, $sourceContent, 'source');
        $continuation = $this->continuationType($definition);
        $collection = $continuation->continuationCollectionField();

        if (! is_array($sourceContent[$collection] ?? null)) {
            throw new InvalidArgumentException('The content type does not define a valid continuation limit.');
        }

        return $continuation->maximumContinuationEntries() - count($sourceContent[$collection]);
    }

    /**
     * @param  array<string, mixed>  $sourceContent
     * @return array<string, mixed>
     */
    private function buildProposalSchema(
        ContentTypeDefinition $definition,
        array $sourceContent,
        ?int $requestedEntryCount = null,
    ): array {
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

        $maximumTotalEntries = $continuation->maximumContinuationEntries();
        $maximumEntriesPerRequest = $continuation->maximumContinuationEntriesPerRequest();

        if ($maximumTotalEntries < 1 || $maximumEntriesPerRequest < 1) {
            throw new InvalidArgumentException('The content type does not define a valid continuation limit.');
        }

        $remainingTotalEntries = $maximumTotalEntries - count($sourceEntries);

        if ($remainingTotalEntries < 1) {
            throw ValidationException::withMessages([
                $collection => ['This discussion has reached its maximum number of entries and cannot be continued.'],
            ]);
        }

        $maximumEntries = min($remainingTotalEntries, $maximumEntriesPerRequest);

        if ($requestedEntryCount !== null && ($requestedEntryCount < 1 || $requestedEntryCount > $maximumEntries)) {
            throw ValidationException::withMessages([
                'entry_count' => ['The requested number of entries exceeds the remaining discussion capacity or the per-request limit.'],
            ]);
        }

        $entrySchema = $this->expandContinuationNumberBounds(
            $collectionSchema['items'],
            $maximumEntriesPerRequest,
            $maximumTotalEntries,
        );
        unset($entrySchema['properties'][$numberField]);
        $entrySchema['required'] = array_values(array_diff($entrySchema['required'] ?? [], [$numberField]));

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'entries' => [
                    'type' => 'array',
                    'minItems' => $requestedEntryCount ?? 1,
                    'maxItems' => $requestedEntryCount ?? $maximumEntries,
                    'items' => $entrySchema,
                ],
            ],
            'required' => ['entries'],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function expandContinuationNumberBounds(array $schema, int $perRequestLimit, int $totalLimit): array
    {
        if (($schema['type'] ?? null) === 'integer' && ($schema['maximum'] ?? null) === $perRequestLimit) {
            $schema['maximum'] = $totalLimit;
        }

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->expandContinuationNumberBounds($value, $perRequestLimit, $totalLimit);
            }
        }

        return $schema;
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
        ?int $requestedEntryCount = null,
    ): array {
        return $this->composeGenerated($definition, $sourceContent, $proposal, $requestedEntryCount, false)->content;
    }

    public function composeGenerated(
        ContentTypeDefinition $definition,
        array $sourceContent,
        array $proposal,
        ?int $requestedEntryCount,
        bool $normalizeQuotes,
    ): ComposedContentContinuation {
        $continuation = $this->continuationType($definition);
        $collection = $continuation->continuationCollectionField();
        $numberField = $continuation->continuationNumberField();

        $sourceContent = $this->validateDocument($definition, $sourceContent, 'source');
        $sourceEntries = $sourceContent[$collection];
        $proposalSchema = $this->buildProposalSchema($definition, $sourceContent, $requestedEntryCount);
        $entryProperties = array_keys($proposalSchema['properties']['entries']['items']['properties']);
        $availableEntries = $proposalSchema['properties']['entries']['maxItems'];
        $proposalRules = [
            'continuation' => ['required', 'array:entries'],
            'continuation.entries' => array_values(array_filter([
                'required',
                'array',
                'list',
                $requestedEntryCount === null ? null : 'size:'.$requestedEntryCount,
                'min:1',
                'max:'.$availableEntries,
            ])),
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

        $normalizationDiagnostics = [
            'quotes_preserved' => 0,
            'quotes_normalized' => 0,
            'quotes_dropped' => 0,
        ];

        if ($normalizeQuotes && $definition instanceof GeneratedContinuationNormalizer) {
            $normalization = $definition->normalizeGeneratedContinuation($combinedContent, count($sourceEntries));
            $combinedContent = $normalization['content'];
            $normalizationDiagnostics = [
                'quotes_preserved' => $normalization['quotes_preserved'],
                'quotes_normalized' => $normalization['quotes_normalized'],
                'quotes_dropped' => $normalization['quotes_dropped'],
            ];
        }

        return new ComposedContentContinuation(
            content: $this->validateDocument($definition, $combinedContent, 'combined'),
            quoteNormalization: $normalizationDiagnostics,
        );
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
        $continuation = $this->continuationType($definition);
        $collection = $continuation->continuationCollectionField();
        $schema = $definition->outputSchema();
        $properties = $schema['properties'] ?? null;

        if (($schema['type'] ?? null) !== 'object' || ! is_array($properties) || $properties === []) {
            throw new InvalidArgumentException('The registered content type has an invalid output schema.');
        }

        $rules = [
            $attributePrefix => ['required', 'array:'.implode(',', array_keys($properties))],
        ];

        foreach ($definition->validationRules() as $attribute => $attributeRules) {
            if ($attribute === $collection) {
                $attributeRules = array_values(array_filter(
                    $attributeRules,
                    static fn (mixed $rule): bool => ! (is_string($rule) && str_starts_with($rule, 'max:')),
                ));
                $attributeRules[] = 'max:'.$continuation->maximumContinuationEntries();
            }

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
