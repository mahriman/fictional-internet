<?php

namespace App\Http\Requests;

use App\ContentTypes\ContentTypeRegistry;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GenerateContentRequest extends FormRequest
{
    /**
     * Redirect validation failures with only the fields accepted by this form.
     */
    protected function failedValidation(Validator $validator): never
    {
        $redirectUrl = $this->getRedirectUrl();
        $response = $this->redirector->to($redirectUrl)
            ->withInput($this->only(['content_type', 'prompt']))
            ->withErrors($validator, $this->errorBag);

        throw (new ValidationException($validator, $response))
            ->errorBag($this->errorBag)
            ->redirectTo($redirectUrl);
    }

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
        return [
            'content_type' => ['required', 'string', Rule::in(array_keys($contentTypes->all()))],
            'prompt' => ['required', 'string', 'regex:/\S/u', 'max:10000'],
        ];
    }
}
