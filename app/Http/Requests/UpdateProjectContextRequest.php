<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

class UpdateProjectContextRequest extends FormRequest
{
    /** 16,000 characters stay below MySQL TEXT's byte limit, including four-byte UTF-8 characters. */
    public const MAX_SECTION_LENGTH = 16000;

    public const MAX_TOTAL_LENGTH = 40000;

    /** @var list<string> */
    private array $sectionFields = [
        'setting',
        'time_period',
        'locations',
        'people',
        'organizations',
        'canon_notes',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('update', $project) ?? false);
    }

    /**
     * Normalize submitted text and empty sections before validation.
     */
    protected function prepareForValidation(): void
    {
        $context = $this->input('context');

        if (! is_array($context)) {
            return;
        }

        foreach ($this->sectionFields as $field) {
            if (is_string($context[$field] ?? null)) {
                $context[$field] = trim($context[$field]) === '' ? null : trim($context[$field]);
            }
        }

        $this->merge(['context' => $context]);
    }

    /**
     * Add the total-size check after field-level validation succeeds.
     *
     * @return array<int, callable(LaravelValidator): void>
     */
    public function after(): array
    {
        return [function (LaravelValidator $validator): void {
            if (! $validator->errors()->isEmpty()) {
                return;
            }

            $submittedContext = $this->input('context');

            if (! is_array($submittedContext)) {
                return;
            }

            $totalLength = 0;

            foreach ($this->sectionFields as $field) {
                $value = $submittedContext[$field] ?? null;

                if (is_string($value)) {
                    $totalLength += mb_strlen($value, 'UTF-8');
                }
            }

            if ($totalLength > self::MAX_TOTAL_LENGTH) {
                $validator->errors()->add(
                    'context',
                    'The combined project context may not exceed 40,000 characters.',
                );
            }
        }];
    }

    /**
     * Flash only supported text sections after a validation failure.
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): never
    {
        $redirectUrl = $this->getRedirectUrl();
        $submittedContext = $this->input('context');
        $safeContext = [];

        if (is_array($submittedContext)) {
            foreach (Arr::only($submittedContext, $this->sectionFields) as $field => $value) {
                if (is_string($value) || $value === null) {
                    $safeContext[$field] = $value;
                }
            }
        }

        $response = $this->redirector->to($redirectUrl)
            ->withInput(['context' => $safeContext])
            ->withErrors($validator, $this->errorBag);

        throw (new ValidationException($validator, $response))
            ->errorBag($this->errorBag)
            ->redirectTo($redirectUrl);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $allowedFields = implode(',', $this->sectionFields);

        return [
            'context' => ['required', 'array:'.$allowedFields],
            'context.setting' => ['nullable', 'string', 'max:'.self::MAX_SECTION_LENGTH],
            'context.time_period' => ['nullable', 'string', 'max:'.self::MAX_SECTION_LENGTH],
            'context.locations' => ['nullable', 'string', 'max:'.self::MAX_SECTION_LENGTH],
            'context.people' => ['nullable', 'string', 'max:'.self::MAX_SECTION_LENGTH],
            'context.organizations' => ['nullable', 'string', 'max:'.self::MAX_SECTION_LENGTH],
            'context.canon_notes' => ['nullable', 'string', 'max:'.self::MAX_SECTION_LENGTH],
        ];
    }
}
