<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateAccountProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    protected function failedValidation(Validator $validator): never
    {
        $safeInput = [];

        foreach (['name', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $safeInput[$field] = $this->input($field);
            }
        }

        $redirectUrl = $this->getRedirectUrl();
        $response = $this->redirector->to($redirectUrl)
            ->withErrors($validator, $this->errorBag)
            ->withInput($safeInput);

        throw (new ValidationException($validator, $response))
            ->errorBag($this->errorBag)
            ->redirectTo($redirectUrl);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->getKey())],
        ];
    }
}
