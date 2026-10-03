@extends('layouts.app')

@section('title', $project->name.' · Fictional Internet')

@section('content')
    <section>
        <a href="{{ route('projects.index') }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">← All projects</a>
        <div class="mt-5 flex flex-col justify-between gap-5 sm:flex-row sm:items-start">
            <div class="max-w-3xl">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">Project</p>
                <h1 class="mt-2 break-words text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{{ $project->name }}</h1>
                @if ($project->description)
                    <p class="mt-4 whitespace-pre-line leading-7 text-slate-600">{{ $project->description }}</p>
                @endif
                <p class="mt-5 text-sm text-slate-500">Created {{ $project->created_at->format('M j, Y') }} · Updated {{ $project->updated_at->format('M j, Y') }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('projects.generated-content.create', $project) }}" class="inline-flex justify-center rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Generate content</a>
                <a href="{{ route('projects.edit', $project) }}" class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Edit project</a>
            </div>
        </div>
    </section>

    <section aria-labelledby="generated-content-heading" class="mt-10 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <div class="border-b border-slate-100 pb-5">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">Workspace</p>
            <h2 id="generated-content-heading" class="mt-2 text-xl font-semibold text-slate-950">Generated content</h2>
        </div>
        @if ($generatedContents->isEmpty())
            <div class="py-12 text-center">
                <div class="mx-auto grid size-12 place-items-center rounded-full bg-slate-100 text-slate-500" aria-hidden="true">✦</div>
                <p class="mt-4 font-medium text-slate-900">No content has been generated for this project yet.</p>
                <p class="mt-2 text-sm text-slate-600">Generated stories and conversations will appear here.</p>
                <a href="{{ route('projects.generated-content.create', $project) }}" class="mt-5 inline-flex rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Generate your first content</a>
            </div>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($generatedContents as $generatedContent)
                    @php($contentType = $contentTypes[$generatedContent->content_type] ?? null)
                    @php($contentTypeLabel = $contentType?->label() ?? $generatedContent->content_type)
                    <li class="py-5 first:pt-6 last:pb-0">
                        <a href="{{ route('projects.generated-content.show', ['project' => $project, 'generatedContent' => $generatedContent]) }}" class="group block rounded-lg focus:outline-2 focus:outline-offset-4 focus:outline-indigo-700">
                            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                                <div>
                                    <p class="font-semibold text-slate-950 group-hover:text-indigo-700">{{ $generatedContent->title ?: $contentTypeLabel }}</p>
                                    <p class="mt-1 text-sm text-slate-600">{{ $contentTypeLabel }}</p>
                                </div>
                                <time class="text-sm text-slate-500" datetime="{{ $generatedContent->created_at->toIso8601String() }}">{{ $generatedContent->created_at->format('M j, Y') }}</time>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="mt-8 rounded-2xl border border-rose-200 bg-white p-6 sm:p-8">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-semibold text-slate-950">Delete this project</h2>
                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">Deleting this project permanently deletes its generated content and complete version history.</p>
            </div>
            <form method="POST" action="{{ route('projects.destroy', $project) }}" onsubmit="return window.confirm('Deleting this project permanently deletes its generated content and version history. Continue?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="w-full rounded-lg border border-rose-300 px-4 py-2.5 text-sm font-semibold text-rose-800 hover:bg-rose-50 focus:outline-2 focus:outline-offset-2 focus:outline-rose-700 sm:w-auto">Delete project</button>
            </form>
        </div>
    </section>
@endsection
