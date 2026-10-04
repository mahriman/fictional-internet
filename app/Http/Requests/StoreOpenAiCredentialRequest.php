<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreOpenAiCredentialRequest extends FormRequest
{
    public function prepareForValidation(): void
    {
        if (is_string($this->input('api_key'))) {
            $this->merge(['api_key' => trim($this->input('api_key'))]);
        }
    }

    /**
     * Redirect failures without flashing secret-bearing request input.
     */
    protected function failedValidation(Validator $validator): never
    {
        $redirectUrl = $this->getRedirectUrl();
        $response = $this->redirector->to($redirectUrl)
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
            'api_key' => ['required', 'string', 'max:512'],
        ];
    }
}
