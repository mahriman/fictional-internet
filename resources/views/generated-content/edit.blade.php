@extends('layouts.app')

@section('title', 'Edit version '.$version->version_number.' · '.$project->name)

@section('content')
    <section class="mx-auto max-w-3xl">
        <a href="{{ route('projects.generated-content.versions.show', [$project, $generatedContent, $version->version_number]) }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">← Back to version {{ $version->version_number }}</a>

        <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">{{ $contentTypeLabel }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Create an edited version</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">This creates a new version based on version {{ $version->version_number }}. The selected version will remain unchanged.</p>

            @include($editingView, ['content' => $structuredContent])
        </div>
    </section>
@endsection
