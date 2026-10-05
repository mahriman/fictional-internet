@extends('layouts.app')

@section('title', ($versionTitle ?: $contentTypeLabel).' · '.$project->name)

@section('content')
    <section class="mx-auto max-w-4xl">
        <a href="{{ route('projects.show', $project) }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">← Back to {{ $project->name }}</a>
        <article class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <header class="border-b border-slate-100 px-5 py-5 sm:px-8">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">{{ $contentTypeLabel }}</p>
                <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-500">
                        <span>Version {{ $version->version_number }}</span>
                        <span>{{ $version->origin === \App\Enums\GeneratedContentVersionOrigin::AiGenerated ? 'AI-generated' : 'Manually edited' }}</span>
                        <time datetime="{{ $version->created_at->toIso8601String() }}">Created {{ $version->created_at->format('M j, Y · g:i A') }}</time>
                        @if ($version->basedOnVersion)
                            <span>Based on version {{ $version->basedOnVersion->version_number }}</span>
                        @endif
                        @if ($isLatestVersion)
                            <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">Latest version</span>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('projects.generated-content.versions.export', [$project, $generatedContent, $version->version_number, 'pdf']) }}" class="inline-flex justify-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Download PDF</a>
                        <a href="{{ route('projects.generated-content.versions.export', [$project, $generatedContent, $version->version_number, 'png']) }}" class="inline-flex justify-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Download PNG</a>
                        @if ($continuationSupported && $continuationCapacity > 0)
                            <a href="{{ route('projects.generated-content.versions.continuations.create', [$project, $generatedContent, $version->version_number]) }}" class="inline-flex justify-center rounded-lg border border-indigo-300 px-3 py-2 text-sm font-semibold text-indigo-800 hover:bg-indigo-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Continue from version {{ $version->version_number }}</a>
                        @endif
                        @if ($editingView !== null)
                            <a href="{{ route('projects.generated-content.versions.edit', [$project, $generatedContent, $version->version_number]) }}" class="inline-flex justify-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Edit this version</a>
                        @else
                            <p class="self-center text-sm text-slate-500">A dedicated editor is unavailable for this content type.</p>
                        @endif
                    </div>
                </div>
                    @if ($continuationSupported && $continuationCapacity === 0)
                        <p class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">This discussion has reached its maximum supported size and cannot be continued from this version.</p>
                    @elseif ($continuationSupported && $continuationCapacity === null)
                        <p class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">Continuation is unavailable for this stored version.</p>
                    @endif
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

            @if ($referenceSummaries !== [])
                <section class="border-t border-slate-100 px-5 py-5 sm:px-8" aria-labelledby="references-heading">
                    <h2 id="references-heading" class="text-sm font-semibold text-slate-900">References used</h2>
                    <ul class="mt-3 space-y-2">
                        @foreach ($referenceSummaries as $reference)
                            <li class="text-sm leading-6 text-slate-700">
                                @if ($reference['url'] !== null)
                                    <a href="{{ $reference['url'] }}" class="font-medium text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-900">
                                        {{ $reference['title'] }}
                                    </a>
                                @else
                                    <span class="font-medium">{{ $reference['title'] }}</span>
                                @endif
                                <span class="text-slate-500">· {{ $reference['content_type'] }} · version {{ $reference['version_number'] ?? 'unavailable' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </article>

        <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="version-history-heading">
            <div class="border-b border-slate-100 pb-4">
                <h2 id="version-history-heading" class="text-lg font-semibold text-slate-950">Version history</h2>
                <p class="mt-1 text-sm text-slate-600">Each version is preserved. Edited versions identify the version they were based on.</p>
            </div>
            <ol class="mt-2 divide-y divide-slate-100">
                @foreach ($versionHistory as $historyVersion)
                    <li class="py-4 first:pt-4 last:pb-0">
                        <a href="{{ route('projects.generated-content.versions.show', [$project, $generatedContent, $historyVersion->version_number]) }}" @if ($version->is($historyVersion)) aria-current="page" @endif class="flex flex-col gap-2 rounded-lg focus:outline-2 focus:outline-offset-4 focus:outline-indigo-700 sm:flex-row sm:items-center sm:justify-between">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-slate-950">Version {{ $historyVersion->version_number }}</span>
                                <span class="text-sm text-slate-600">{{ $historyVersion->origin === \App\Enums\GeneratedContentVersionOrigin::AiGenerated ? 'AI-generated' : 'Manually edited' }}</span>
                                @if ($historyVersion->basedOnVersion)
                                    <span class="text-sm text-slate-500">Based on version {{ $historyVersion->basedOnVersion->version_number }}</span>
                                @endif
                                @if ($historyVersion->version_number === $versionHistory->first()->version_number)
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">Latest</span>
                                @endif
                                @if ($version->is($historyVersion))
                                    <span class="text-sm font-medium text-indigo-700">Selected</span>
                                @endif
                            </span>
                            <time class="text-sm text-slate-500" datetime="{{ $historyVersion->created_at->toIso8601String() }}">{{ $historyVersion->created_at->format('M j, Y · g:i A') }}</time>
                        </a>
                    </li>
                @endforeach
            </ol>
        </section>
    </section>
@endsection
