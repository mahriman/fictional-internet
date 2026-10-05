@extends('layouts.app')

@section('title', 'Generation temporarily limited')

@section('content')
    <section class="mx-auto max-w-2xl rounded-2xl border border-amber-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-950">Generation temporarily limited</h1>
        <p class="mt-3 text-sm leading-6 text-slate-700">You have submitted several generation requests recently. Please wait a minute before trying again. No provider request was made for this submission.</p>
        @if ($project instanceof \App\Models\Project)
            <a href="{{ route('projects.show', $project) }}" class="mt-5 inline-flex rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Return to project</a>
        @endif
    </section>
@endsection
