<?php

namespace App\Http\Requests;

use App\ContentTypes\ContentTypeRegistry;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
            ->withInput($this->supportedInput())
            ->withErrors($validator, $this->errorBag);

        throw (new ValidationException($validator, $response))
            ->errorBag($this->errorBag)
            ->redirectTo($redirectUrl);
    }

    /**
     * Resolve selected UUID/version pairs in the authorized project before the provider can be called.
     *
     * @return array<int, callable(\Illuminate\Validation\Validator): void>
     */
    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator): void {
            if (! $validator->errors()->isEmpty()) {
                return;
            }

            $project = $this->route('project');
            $submittedReferences = $this->input('references', []);

            if (! $project instanceof Project || ! is_array($submittedReferences) || $submittedReferences === []) {
                return;
            }

            $selections = [];
            $seenSelections = [];

            foreach ($submittedReferences as $index => $reference) {
                [$contentUuid, $versionNumber] = explode(':', $reference, 2);
                $contentUuid = Str::lower($contentUuid);
                $versionNumber = filter_var($versionNumber, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                if (! Str::isUuid($contentUuid) || $versionNumber === false) {
                    $validator->errors()->add("references.{$index}", 'Choose a valid content version from this project.');

                    continue;
                }

                $selectionKey = $contentUuid.':'.$versionNumber;

                if (isset($seenSelections[$selectionKey])) {
                    $validator->errors()->add("references.{$index}", 'A reference version may only be selected once.');

                    continue;
                }

                $seenSelections[$selectionKey] = true;
                $selections[] = [
                    'index' => $index,
                    'content_uuid' => $contentUuid,
                    'version_number' => $versionNumber,
                ];
            }

            if (! $validator->errors()->isEmpty() || $selections === []) {
                return;
            }

            $contents = $project->generatedContents()
                ->whereIn('uuid', array_column($selections, 'content_uuid'))
                ->get(['id', 'uuid'])
                ->keyBy('uuid');

            foreach ($selections as $selection) {
                if (! $contents->has($selection['content_uuid'])) {
                    $validator->errors()->add(
                        "references.{$selection['index']}",
                        'A selected reference is unavailable in this project.',
                    );
                }
            }

            if (! $validator->errors()->isEmpty()) {
                return;
            }

            $versions = GeneratedContentVersion::query()
                ->where(function (Builder $query) use ($selections, $contents): void {
                    foreach ($selections as $selection) {
                        $contentId = $contents->get($selection['content_uuid'])->getKey();

                        $query->orWhere(function (Builder $pairQuery) use ($contentId, $selection): void {
                            $pairQuery->where('generated_content_id', $contentId)
                                ->where('version_number', $selection['version_number']);
                        });
                    }
                })
                ->get(['generated_content_id', 'version_number'])
                ->mapWithKeys(static fn (GeneratedContentVersion $version): array => [
                    $version->generated_content_id.':'.$version->version_number => true,
                ]);

            foreach ($selections as $selection) {
                $contentId = $contents->get($selection['content_uuid'])->getKey();
                $selectionKey = $contentId.':'.$selection['version_number'];

                if (! $versions->has($selectionKey)) {
                    $validator->errors()->add(
                        "references.{$selection['index']}",
                        'A selected version is unavailable. Choose another version from this project.',
                    );
                }
            }
        }];
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
     * Flash only supported and safely shaped generation fields.
     *
     * @return array{content_type?: string, prompt?: string, references?: list<string>}
     */
    private function supportedInput(): array
    {
        $safeInput = [];

        foreach (['content_type', 'prompt'] as $field) {
            if (is_string($this->input($field))) {
                $safeInput[$field] = $this->input($field);
            }
        }

        $references = $this->input('references');

        if (! is_array($references)) {
            return $safeInput;
        }

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

        return $safeInput;
    }
}
