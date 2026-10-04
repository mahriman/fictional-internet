<?php

namespace App\Http\Requests;

use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Contracts\ContentTypeDefinition;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditGeneratedContentRequest extends FormRequest
{
    /** @var array<int, string> */
    private array $allowedContentFields = [];

    /** @var array<string, array<int, string>> */
    private array $editingRules = [];

    /** @var array<string, mixed> */
    private array $sourceContent = [];

    private ?ContentTypeDefinition $definition = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('view', $project) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(ContentTypeRegistry $contentTypes): array
    {
        $generatedContent = $this->route('generatedContent');
        $versionNumber = $this->route('versionNumber');

        abort_unless($generatedContent instanceof GeneratedContent, 404);

        $sourceVersion = $generatedContent->versions()
            ->where('version_number', $versionNumber)
            ->firstOrFail();
        abort_unless($sourceVersion instanceof GeneratedContentVersion, 404);

        $definition = $contentTypes->all()[$generatedContent->content_type] ?? null;
        $editingView = $definition?->editingView();

        abort_unless($editingView !== null && view()->exists($editingView), 404);

        $properties = $definition->outputSchema()['properties'] ?? null;

        abort_unless(is_array($properties) && $properties !== [], 404);

        $this->allowedContentFields = array_keys($properties);
        $this->definition = $definition;
        $this->sourceContent = is_array($sourceVersion->content) ? $sourceVersion->content : [];
        $this->editingRules = $definition->editingValidationRules($this->sourceContent);

        $rules = [
            'content' => ['required', 'array:'.implode(',', $this->allowedContentFields)],
        ];

        foreach ($this->editingRules as $field => $fieldRules) {
            $rules['content.'.$field] = $fieldRules;
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['content', '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'Unexpected request fields are not allowed.');
            }

            if ($validator->errors()->isNotEmpty() || $this->definition === null) {
                return;
            }

            $validatedContent = $validator->validated()['content'] ?? null;

            if (! is_array($validatedContent)) {
                return;
            }

            $preparedContent = $this->definition->prepareEditedContent($validatedContent, $this->sourceContent);

            foreach ($this->definition->semanticValidationErrors($preparedContent) as $attribute => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add('content.'.$attribute, $message);
                }
            }
        });
    }

    /**
     * Flash only schema-supported fields after a validation failure.
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): never
    {
        $redirectUrl = $this->getRedirectUrl();
        $submittedContent = $this->input('content');
        $safeContent = is_array($submittedContent)
            ? Arr::only($submittedContent, $this->allowedContentFields)
            : [];

        $nestedAllowedFields = $this->nestedAllowedFields();
        $nestedCollections = [];

        foreach ($nestedAllowedFields as $path => $allowedFields) {
            $segments = explode('.', $path);
            $collection = $segments[0];

            if (count($segments) !== 2 || $segments[1] !== '*') {
                continue;
            }

            $nestedCollections[$collection] = $allowedFields;

            if (! is_array($safeContent[$collection] ?? null)) {
                continue;
            }

            $nestedItems = $safeContent[$collection];
            $expectedCount = $this->expectedNestedCount($collection);

            if (! array_is_list($nestedItems)
                || ($expectedCount !== null && count($nestedItems) !== $expectedCount)) {
                unset($safeContent[$collection]);

                continue;
            }

            $safeContent[$collection] = array_map(
                static fn (mixed $item): array => is_array($item) ? Arr::only($item, $allowedFields) : [],
                $nestedItems,
            );
        }

        uksort($nestedAllowedFields, static fn (string $left, string $right): int => substr_count($left, '.') <=> substr_count($right, '.'));

        foreach ($nestedAllowedFields as $path => $allowedFields) {
            if (substr_count($path, '.') < 2) {
                continue;
            }

            $collection = explode('.', $path)[0];

            if (! isset($nestedCollections[$collection]) || ! isset($safeContent[$collection])) {
                continue;
            }

            $this->filterNestedPath($safeContent, explode('.', $path), $allowedFields);
        }

        $response = $this->redirector->to($redirectUrl)
            ->withInput(['content' => $safeContent])
            ->withErrors($validator, $this->errorBag);

        throw (new ValidationException($validator, $response))
            ->errorBag($this->errorBag)
            ->redirectTo($redirectUrl);
    }

    /** @return array<string, list<string>> */
    private function nestedAllowedFields(): array
    {
        $allowedFields = [];

        foreach ($this->editingRules as $path => $rules) {
            if (! str_contains($path, '.*')) {
                continue;
            }

            foreach ($rules as $rule) {
                if (! is_string($rule) || ! str_starts_with($rule, 'array:')) {
                    continue;
                }

                $allowedFields[$path] = explode(',', substr($rule, 6));
            }
        }

        return $allowedFields;
    }

    /**
     * @param  array<string, mixed>  $container
     * @param  list<string>  $segments
     * @param  list<string>  $allowedFields
     */
    private function filterNestedPath(array &$container, array $segments, array $allowedFields): void
    {
        $segment = array_shift($segments);

        if ($segment === '*') {
            if (! array_is_list($container)) {
                return;
            }

            foreach ($container as &$item) {
                if ($segments === []) {
                    $item = is_array($item) ? Arr::only($item, $allowedFields) : [];
                } elseif (is_array($item)) {
                    $this->filterNestedPath($item, $segments, $allowedFields);
                }
            }
            unset($item);

            return;
        }

        if (! is_string($segment) || ! array_key_exists($segment, $container)) {
            return;
        }

        if ($segments === []) {
            if (is_array($container[$segment])) {
                $container[$segment] = Arr::only($container[$segment], $allowedFields);
            }

            return;
        }

        if (is_array($container[$segment])) {
            $this->filterNestedPath($container[$segment], $segments, $allowedFields);
        }
    }

    private function expectedNestedCount(string $field): ?int
    {
        foreach ($this->editingRules[$field] ?? [] as $rule) {
            if (! is_string($rule) || preg_match('/\Asize:(\d+)\z/', $rule, $matches) !== 1) {
                continue;
            }

            return (int) $matches[1];
        }

        return null;
    }
}
