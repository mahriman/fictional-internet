@extends('layouts.app')

@section('title', 'Project export unavailable · '.$project->name)

@section('content')
    <section class="mx-auto max-w-2xl rounded-2xl border border-amber-200 bg-amber-50 p-6 sm:p-8" aria-labelledby="export-error-heading">
        <h1 id="export-error-heading" class="text-xl font-semibold text-amber-950">Project export unavailable</h1>
        <p class="mt-3 text-sm leading-6 text-amber-900">{{ $message }}</p>
        <a href="{{ route('projects.show', $project) }}" class="mt-5 inline-flex rounded-lg border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-950 hover:bg-amber-100 focus:outline-2 focus:outline-offset-2 focus:outline-amber-700">Back to project</a>
    </section>
@endsection
