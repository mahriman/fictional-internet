@php($selectedReferences = old('references', []))
<div>
    <label for="references" class="block text-sm font-medium text-slate-700">Reference material <span class="font-normal text-slate-500">(optional, up to five versions)</span></label>
    <p id="references-help" class="mt-2 text-xs leading-5 text-slate-500">Optionally select up to five exact immutable versions of existing project content as supplementary source material for this request. References are separate from the automatically supplied Project Context, do not automatically become canonical truth, and the exact versions you choose are captured in the new version’s provenance. Multiple selections are supplied in the order shown here. Deselect an option to remove it.</p>

    @if ($generatedContents->isEmpty() || $generatedContents->every(fn ($generatedContent) => $generatedContent->versions->isEmpty()))
        <p class="mt-3 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">No generated versions are available to reference yet.</p>
    @else
        <select id="references" name="references[]" multiple size="9" aria-describedby="references-help" aria-invalid="{{ $errors->has('references') || collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'references.')) ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            @foreach ($generatedContents as $generatedContent)
                @php($referenceType = $contentTypes[$generatedContent->content_type] ?? null)
                @php($referenceTypeLabel = $referenceType?->label() ?? $generatedContent->content_type)
                @foreach ($generatedContent->versions as $referenceVersion)
                    @php($referenceValue = $generatedContent->uuid.':'.$referenceVersion->version_number)
                    <option value="{{ $referenceValue }}" @selected(is_array($selectedReferences) && in_array($referenceValue, $selectedReferences, true))>{{ $referenceTypeLabel }} · {{ $generatedContent->title }} · Version {{ $referenceVersion->version_number }}</option>
                @endforeach
            @endforeach
        </select>
    @endif

    @error('references')
        <p role="alert" class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
    @enderror
    @foreach ($errors->getMessages() as $errorKey => $messages)
        @if (str_starts_with($errorKey, 'references.'))
            <p role="alert" class="mt-2 text-sm font-medium text-rose-700">{{ $messages[0] }}</p>
        @endif
    @endforeach
</div>
