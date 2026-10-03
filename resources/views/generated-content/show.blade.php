@extends('layouts.app')

@section('title', ($generatedContent->title ?: $contentTypeLabel).' · '.$project->name)

@section('content')
    <section class="mx-auto max-w-4xl">
        <a href="{{ route('projects.show', $project) }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">← Back to {{ $project->name }}</a>
        <article class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <header class="border-b border-slate-100 px-5 py-5 sm:px-8">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">{{ $contentTypeLabel }}</p>
                <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-500">
                    <span>Version {{ $version->version_number }}</span>
                    <time datetime="{{ $version->created_at->toIso8601String() }}">Generated {{ $version->created_at->format('M j, Y · g:i A') }}</time>
                </div>
            </header>

            @if ($presentationView !== null)
                @include($presentationView, ['content' => $structuredContent])
            @else
                <section class="p-6 sm:p-8" aria-labelledby="structured-content-heading">
                    <h1 id="structured-content-heading" class="text-2xl font-semibold tracking-tight text-slate-950">Structured content</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-600">No dedicated presentation is available for this content type. Its stored data is shown below.</p>
                    <pre class="mt-5 overflow-x-auto rounded-xl bg-slate-950 p-5 text-sm leading-6 text-slate-100"><code>{{ json_encode($structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                </section>
            @endif

            @if ($presentationView !== null)
                <details class="border-t border-slate-100 px-5 py-4 sm:px-8">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-700 marker:text-indigo-700 focus:outline-2 focus:outline-offset-4 focus:outline-indigo-700">View structured data</summary>
                    <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-950 p-5 text-sm leading-6 text-slate-100"><code>{{ json_encode($structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                </details>
            @endif
        </article>
    </section>
@endsection
