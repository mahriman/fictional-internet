<?php

namespace App\Http\Requests;

use App\Enums\GenerationAttemptStatus;
use App\Models\GeneratedContent;
use App\Models\GenerationAttempt;
use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreGeneratedContentContinuationRequest extends FormRequest
{
    private const string ATTEMPT_TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    protected function failedValidation(Validator $validator): never
    {
        $redirectUrl = $this->getRedirectUrl();
        $response = $this->redirector->to($redirectUrl)
            ->withInput($this->safeContinuationInput())
            ->withErrors($validator, $this->errorBag);

        throw (new ValidationException($validator, $response))
            ->errorBag($this->errorBag)
            ->redirectTo($redirectUrl);
    }

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('view', $project) ?? false);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'attempt_token' => ['required', 'string', 'regex:'.self::ATTEMPT_TOKEN_PATTERN],
            'continuation_instructions' => ['required', 'string', 'regex:/\S/u', 'max:10000'],
            'entry_count' => ['required', 'integer', 'min:1'],
            'references' => ['sometimes', 'array', 'max:5'],
            'references.*' => [
                'required',
                'string',
                'regex:/\A[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}:[1-9][0-9]*\z/',
                'distinct:strict',
            ],
        ];
    }

    /**
     * Flash only supported form values and keep the opaque token only while it is issued
     * for this exact project, content record and source version.
     *
     * @return array<string, mixed>
     */
    public function safeContinuationInput(bool $includeAttemptToken = true): array
    {
        $safeInput = [];

        if (is_string($this->input('continuation_instructions'))) {
            $safeInput['continuation_instructions'] = $this->input('continuation_instructions');
        }

        $entryCount = $this->input('entry_count');

        if (is_int($entryCount) || (is_string($entryCount) && preg_match('/\A[0-9]+\z/', $entryCount) === 1)) {
            $safeInput['entry_count'] = $entryCount;
        }

        $references = $this->input('references');

        if (is_array($references)) {
            $safeReferences = [];

            foreach ($references as $reference) {
                if (! is_string($reference)
                    || preg_match('/\A[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}:[1-9][0-9]*\z/', $reference) !== 1) {
                    continue;
                }

                $safeReferences[] = $reference;

                if (count($safeReferences) === 5) {
                    break;
                }
            }

            if ($safeReferences !== []) {
                $safeInput['references'] = $safeReferences;
            }
        }

        $attemptToken = $this->input('attempt_token');
        $project = $this->route('project');
        $generatedContent = $this->route('generatedContent');
        $versionNumber = filter_var($this->route('versionNumber'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $user = $this->user();

        if ($includeAttemptToken
            && is_string($attemptToken)
            && preg_match(self::ATTEMPT_TOKEN_PATTERN, $attemptToken) === 1
            && $project instanceof Project
            && $generatedContent instanceof GeneratedContent
            && $versionNumber !== false
            && $user !== null) {
            $sourceVersion = $generatedContent->versions()
                ->where('version_number', $versionNumber)
                ->first();

            if ($sourceVersion !== null && GenerationAttempt::query()
                ->where('token_hash', hash('sha256', $attemptToken))
                ->where('user_id', $user->getAuthIdentifier())
                ->where('project_id', $project->getKey())
                ->where('target_generated_content_id', $generatedContent->getKey())
                ->where('source_version_id', $sourceVersion->getKey())
                ->where('status', GenerationAttemptStatus::Issued->value)
                ->exists()) {
                $safeInput['attempt_token'] = $attemptToken;
            }
        }

        return $safeInput;
    }
}
