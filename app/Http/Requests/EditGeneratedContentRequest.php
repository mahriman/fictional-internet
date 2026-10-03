<?php

namespace App\Http\Requests;

use App\ContentTypes\ContentTypeRegistry;
use App\Models\GeneratedContent;
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

        abort_unless($generatedContent instanceof GeneratedContent, 404);

        $definition = $contentTypes->all()[$generatedContent->content_type] ?? null;
        $editingView = $definition?->editingView();

        abort_unless($editingView !== null && view()->exists($editingView), 404);

        $properties = $definition->outputSchema()['properties'] ?? null;

        abort_unless(is_array($properties) && $properties !== [], 404);

        $this->allowedContentFields = array_keys($properties);

        $rules = [
            'content' => ['required', 'array:'.implode(',', $this->allowedContentFields)],
        ];

        foreach ($definition->validationRules() as $field => $fieldRules) {
            if (array_key_exists($field, $properties)) {
                $rules['content.'.$field] = $fieldRules;
            }
        }

        return $rules;
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
        $response = $this->redirector->to($redirectUrl)
            ->withInput(['content' => $safeContent])
            ->withErrors($validator, $this->errorBag);

        throw (new ValidationException($validator, $response))
            ->errorBag($this->errorBag)
            ->redirectTo($redirectUrl);
    }
}
