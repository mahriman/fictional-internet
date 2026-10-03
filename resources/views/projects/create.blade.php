@extends('layouts.app')

@section('title', 'Create project · Fictional Internet')

@section('content')
    <section class="mx-auto max-w-2xl">
        <a href="{{ route('projects.index') }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">← All projects</a>
        <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">New workspace</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Create a project</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">Give your fictional world a name and an optional description.</p>
            @include('projects.partials.form', ['action' => route('projects.store'), 'method' => 'POST', 'buttonText' => 'Create project'])
        </div>
    </section>
@endsection
