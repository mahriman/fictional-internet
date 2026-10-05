@extends('layouts.app')

@section('title', 'Continue '.$contentTypeLabel.' · '.$project->name)

@section('content')
    <section class="mx-auto max-w-3xl">
        <a href="{{ route('projects.generated-content.versions.show', [$project, $generatedContent, $sourceVersion->version_number]) }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">← Back to version {{ $sourceVersion->version_number }}</a>

        <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">{{ $project->name }} · {{ $contentTypeLabel }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Continue {{ $contentTypeLabel }} from version {{ $sourceVersion->version_number }}</h1>
            <p class="mt-2 break-words text-lg font-medium text-slate-800">{{ $sourceTitle }}</p>
            <p class="mt-3 text-sm leading-6 text-slate-600"><span class="font-semibold text-slate-800">Continuation source:</span> version {{ $sourceVersion->version_number }} is the exact base document being extended. New entries will be added to a new immutable version; this source and other existing versions remain unchanged. Optional references provide context and are not appended as discussion entries.</p>

            @if ($hasNewerVersions)
                <p role="status" class="mt-5 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm leading-6 text-indigo-950">Newer versions already exist. Continuing from version {{ $sourceVersion->version_number }} will create a separate branch from this selected version.</p>
            @endif

            <dl class="mt-5 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-3">
                <div><dt class="text-slate-500">Selected source</dt><dd class="mt-1 font-semibold text-slate-900">Version {{ $sourceVersion->version_number }}</dd></div>
                <div><dt class="text-slate-500">Current entries</dt><dd class="mt-1 font-semibold text-slate-900">{{ $entryCount }} of {{ $maximumEntryCount }}</dd></div>
                <div><dt class="text-slate-500">Remaining capacity</dt><dd class="mt-1 font-semibold text-slate-900">{{ $remainingCapacity ?? 'Unavailable' }}</dd></div>
            </dl>

            <aside class="mt-5 rounded-xl border border-indigo-100 bg-indigo-50/60 px-4 py-4" aria-labelledby="project-context-continuation-heading">
                <h2 id="project-context-continuation-heading" class="text-sm font-semibold text-slate-900">Project Context</h2>
                <p class="mt-1 text-sm leading-6 text-slate-700">Reusable information about this project’s fictional world is supplied automatically with the continuation when configured. Some or all context sections may be empty; Project Context is separate from the selected source version and does not need to be selected below.</p>
                <a href="{{ route('projects.context.edit', $project) }}" class="mt-2 inline-flex text-sm font-semibold text-indigo-800 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-950">Review Project Context</a>
            </aside>

            @if ($remainingCapacity === 0)
                <p role="status" class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">This discussion has reached the maximum supported size and cannot be continued from this version.</p>
            @elseif ($remainingCapacity === null)
                <p role="status" class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">This stored version cannot be continued because its content does not pass the current discussion validation rules.</p>
            @else
                @if (! $hasPersonalKey)
                    <p role="status" class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">A personal OpenAI API key is required before you can continue a discussion. Add one in <a href="{{ route('account.settings') }}" class="font-semibold underline underline-offset-2">Account settings</a>. The application will not use a shared server key.</p>
                @endif

                @foreach (['credentials', 'generation', 'continuation', 'continuation_instructions', 'entry_count'] as $errorField)
                    @error($errorField)
                        <p role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-950">{{ $message }} @if ($errorField === 'credentials') <a href="{{ route('account.settings') }}" class="font-semibold underline underline-offset-2">Open Account settings</a>. @endif</p>
                    @enderror
                @endforeach
                @foreach ($errors->getMessages() as $errorField => $messages)
                    @if (! in_array($errorField, ['credentials', 'generation', 'continuation', 'continuation_instructions', 'entry_count'], true) && ! str_starts_with($errorField, 'references'))
                        <p role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-950">{{ $messages[0] }}</p>
                    @endif
                @endforeach

                <form method="POST" action="{{ route('projects.generated-content.versions.continuations.store', [$project, $generatedContent, $sourceVersion->version_number]) }}" class="mt-8 space-y-6" data-generation-form>
                    @csrf
                    <input type="hidden" name="attempt_token" value="{{ $attemptToken }}">

                    <div>
                        <label for="continuation_instructions" class="block text-sm font-medium text-slate-700">What should happen next? <span class="text-rose-700">*</span></label>
                        @php($oldInstructions = old('continuation_instructions'))
                        <textarea id="continuation_instructions" name="continuation_instructions" rows="7" maxlength="10000" required aria-invalid="{{ $errors->has('continuation_instructions') ? 'true' : 'false' }}" aria-describedby="continuation-instructions-help" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ is_string($oldInstructions) ? $oldInstructions : '' }}</textarea>
                        <p id="continuation-instructions-help" class="mt-2 text-xs text-slate-500">Describe how the discussion should develop. Maximum 10,000 characters.</p>
                        @error('continuation_instructions')
                            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        @php($oldEntryCount = old('entry_count'))
                        @php($selectedEntryCount = is_scalar($oldEntryCount) ? filter_var($oldEntryCount, FILTER_VALIDATE_INT) : false)
                        @php($defaultEntryCount = min(3, $remainingCapacity))
                        <label for="entry_count" class="block text-sm font-medium text-slate-700">Number of new entries <span class="text-rose-700">*</span></label>
                        <select id="entry_count" name="entry_count" required aria-invalid="{{ $errors->has('entry_count') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                            @for ($count = 1; $count <= $remainingCapacity; $count++)
                                <option value="{{ $count }}" @selected(($selectedEntryCount !== false && $selectedEntryCount === $count) || ($oldEntryCount === null && $count === $defaultEntryCount))>{{ $count }} {{ $count === 1 ? 'entry' : 'entries' }}</option>
                            @endfor
                        </select>
                        <p class="mt-2 text-xs text-slate-500">Choose between 1 and {{ $remainingCapacity }} new {{ $remainingCapacity === 1 ? 'entry' : 'entries' }}.</p>
                        @error('entry_count')
                            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>

                    @include('generated-content.partials.reference-selector')

                    <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <a href="{{ route('projects.generated-content.versions.show', [$project, $generatedContent, $sourceVersion->version_number]) }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Cancel</a>
                        <div class="flex flex-col items-stretch gap-3 sm:items-end">
                            <p id="continuation-progress" class="text-sm text-slate-600" data-generation-status role="status" aria-live="polite" aria-atomic="true" hidden>Generating the continuation. This can take some time. Please keep this page open…</p>
                            <p class="max-w-xl text-xs leading-5 text-slate-600">@if ($hasPersonalKey) Submitting @else After you add a personal key, submitting @endif sends a request using your personal OpenAI API key. OpenAI may charge your account according to usage and your provider terms; this app does not estimate charges.</p>
                            @if ($hasPersonalKey)
                                <button type="submit" data-generation-submit data-progress-label="Continuing…" class="rounded-lg bg-indigo-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700 disabled:cursor-wait disabled:opacity-70">Create continuation</button>
                            @else
                                <button type="button" disabled class="rounded-lg bg-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-600 disabled:cursor-not-allowed">Add a personal key to continue</button>
                            @endif
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </section>
@endsection
