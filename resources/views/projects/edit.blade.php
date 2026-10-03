@extends('layouts.app')

@section('title', 'Edit project · Fictional Internet')

@section('content')
    <section class="mx-auto max-w-2xl">
        <a href="{{ route('projects.show', $project) }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">← Back to project</a>
        <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">Project settings</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Edit project</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">Update the name or description for this project.</p>
            @include('projects.partials.form', ['action' => route('projects.update', $project), 'method' => 'PUT', 'buttonText' => 'Save changes'])
        </div>
    </section>
@endsection
