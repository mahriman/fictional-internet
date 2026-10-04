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

        foreach ($this->nestedAllowedFields() as $field => $allowedFields) {
            if (! is_array($safeContent[$field] ?? null)) {
                continue;
            }

            $nestedItems = $safeContent[$field];
            $expectedCount = $this->expectedNestedCount($field);

            if (! array_is_list($nestedItems)
                || ($expectedCount !== null && count($nestedItems) !== $expectedCount)) {
                unset($safeContent[$field]);

                continue;
            }

            $safeContent[$field] = array_map(
                static fn (mixed $item): array => is_array($item) ? Arr::only($item, $allowedFields) : [],
                $nestedItems,
            );
        }

        $response = $this->redirector->to($redirectUrl)
            ->withInput(['content' => $safeContent])
            ->withErrors($validator, $this->errorBag);

        throw (new ValidationException($validator, $response))
            ->errorBag($this->errorBag)
            ->redirectTo($redirectUrl);
    }

    /**
     * @return array<string, array<int, string>>
     */
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

                $allowedFields[explode('.', $path, 2)[0]] = explode(',', substr($rule, 6));
            }
        }

        return $allowedFields;
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
