@extends('layouts.app')

@section('title', 'Generate content · '.$project->name)

@section('content')
    <section class="mx-auto max-w-3xl">
        <a href="{{ route('projects.show', $project) }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">← Back to project</a>
        <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">{{ $project->name }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Generate content</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">Choose a format and describe what you want to create. Generation can take some time; keep this page open while it runs.</p>

            @if (! $hasPersonalKey)
                <p role="status" class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">
                    A personal OpenAI API key is required before you can generate content. Add one in <a href="{{ route('account.settings') }}" class="font-semibold underline underline-offset-2">Account settings</a>. The application will not use a shared server key.
                </p>
            @endif

            @error('credentials')
                <p role="alert" class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">
                    {{ $message }} <a href="{{ route('account.settings') }}" class="font-semibold underline underline-offset-2">Open Account settings</a>.
                </p>
            @enderror
            @error('generation')
                <p role="alert" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-950">{{ $message }}</p>
            @enderror

            <form method="POST" action="{{ route('projects.generated-content.store', $project) }}" class="mt-8 space-y-6" data-generation-form>
                @csrf
                <input type="hidden" name="attempt_token" value="{{ $attemptToken }}">

                <div>
                    @php($selectedContentType = old('content_type'))
                    <label for="content_type" class="block text-sm font-medium text-slate-700">Content type <span class="text-rose-700">*</span></label>
                    <select id="content_type" name="content_type" required aria-invalid="{{ $errors->has('content_type') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                        <option value="" disabled @selected(! is_string($selectedContentType) || $selectedContentType === '')>Select a content type</option>
                        @if (is_string($selectedContentType) && ! array_key_exists($selectedContentType, $contentTypes))
                            <option value="{{ $selectedContentType }}" selected disabled>Unavailable content type: {{ $selectedContentType }}</option>
                        @endif
                        @foreach ($contentTypes as $key => $contentType)
                            <option value="{{ $key }}" @selected($selectedContentType === $key)>{{ $contentType->label() }}</option>
                        @endforeach
                    </select>
                    @error('content_type')
                        <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    @php($oldPrompt = old('prompt'))
                    <label for="prompt" class="block text-sm font-medium text-slate-700">Generation prompt <span class="text-rose-700">*</span></label>
                    <textarea id="prompt" name="prompt" rows="9" maxlength="10000" required aria-invalid="{{ $errors->has('prompt') ? 'true' : 'false' }}" aria-describedby="prompt-help" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ is_string($oldPrompt) ? $oldPrompt : '' }}</textarea>
                    <p id="prompt-help" class="mt-2 text-xs text-slate-500">Describe the content and details you want included. Maximum 10,000 characters.</p>
                    @error('prompt')
                        <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                @include('generated-content.partials.reference-selector')

                <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <a href="{{ route('projects.show', $project) }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Cancel</a>
                    <div class="flex flex-col items-stretch gap-3 sm:items-end">
                        <p id="generation-progress" class="text-sm text-slate-600" data-generation-status role="status" aria-live="polite" aria-atomic="true" hidden>Generating your content. This can take some time. Please keep this page open…</p>
                        @if ($hasPersonalKey)
                            <button type="submit" data-generation-submit class="rounded-lg bg-indigo-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700 disabled:cursor-wait disabled:opacity-70">Generate content</button>
                        @else
                            <button type="button" disabled class="rounded-lg bg-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-600 disabled:cursor-not-allowed">Add a personal key to generate</button>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </section>
@endsection
