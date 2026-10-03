@extends('layouts.app')

@section('title', ($generatedContent->title ?: $contentTypeLabel).' · '.$project->name)

@section('content')
    <section class="mx-auto max-w-4xl">
        <a href="{{ route('projects.show', $project) }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">← Back to {{ $project->name }}</a>
        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <header class="border-b border-slate-100 p-6 sm:p-8">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">{{ $contentTypeLabel }}</p>
                <h1 class="mt-2 break-words text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{{ $generatedContent->title ?: $contentTypeLabel }}</h1>
                <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-500">
                    <span>Version {{ $version->version_number }}</span>
                    <time datetime="{{ $version->created_at->toIso8601String() }}">Generated {{ $version->created_at->format('M j, Y · g:i A') }}</time>
                </div>
            </header>
            <div class="p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-slate-950">Generated content</h2>
                <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-950 p-5 text-sm leading-6 text-slate-100"><code>{{ json_encode($version->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
            </div>
        </div>
    </section>
@endsection
