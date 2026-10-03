@extends('layouts.app')

@section('title', 'Your projects · Fictional Internet')

@section('content')
    <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">Workspace</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">Your projects</h1>
            <p class="mt-2 text-slate-600">Organize your fictional worlds and the content you create for them.</p>
        </div>
        <a href="{{ route('projects.create') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Create project</a>
    </div>

    @if ($projects->isEmpty())
        <section class="mt-8 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
            <div class="mx-auto grid size-12 place-items-center rounded-full bg-indigo-50 text-xl font-semibold text-indigo-700" aria-hidden="true">+</div>
            <h2 class="mt-4 text-lg font-semibold text-slate-950">No projects yet</h2>
            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-600">Create a project to give your next fictional publication or online world a home.</p>
            <a href="{{ route('projects.create') }}" class="mt-6 inline-flex rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Create your first project</a>
        </section>
    @else
        <section aria-label="Projects" class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($projects as $project)
                <article class="flex min-h-52 flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-200 hover:shadow-md">
                    <div class="flex-1">
                        <h2 class="text-lg font-semibold text-slate-950">
                            <a href="{{ route('projects.show', $project) }}" class="rounded-sm hover:text-indigo-700 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">{{ $project->name }}</a>
                        </h2>
                        <p class="mt-2 line-clamp-3 text-sm leading-6 text-slate-600">{{ $project->description ?: 'No description added.' }}</p>
                    </div>
                    <div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-4 text-xs text-slate-500">
                        <span>Updated {{ $project->updated_at->format('M j, Y') }}</span>
                        <a href="{{ route('projects.show', $project) }}" class="font-semibold text-indigo-700 hover:text-indigo-900">Open project <span aria-hidden="true">→</span></a>
                    </div>
                </article>
            @endforeach
        </section>
    @endif
@endsection
