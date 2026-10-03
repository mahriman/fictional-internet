@extends('layouts.app')

@section('title', 'Fictional Internet')

@section('content')
    <section class="mx-auto grid max-w-5xl gap-12 rounded-3xl border border-slate-200 bg-white px-6 py-12 shadow-sm sm:px-10 lg:grid-cols-[1.2fr_0.8fr] lg:items-center lg:px-14 lg:py-16">
        <div class="space-y-6">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-700">Fictional Internet</p>
            <h1 class="max-w-xl text-4xl font-semibold tracking-tight text-slate-950 sm:text-5xl">A home for stories from other corners of the web.</h1>
            <p class="max-w-xl text-lg leading-8 text-slate-600">Create projects to organize the fictional publications, conversations, and worlds you build.</p>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('register') }}" class="rounded-lg bg-indigo-700 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Create an account</a>
                <a href="{{ route('login') }}" class="rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Sign in</a>
            </div>
        </div>
        <div class="rounded-2xl bg-slate-950 p-8 text-white">
            <p class="text-sm font-medium text-indigo-300">Start with a project</p>
            <p class="mt-4 text-2xl font-semibold">Keep every fictional world in its own place.</p>
            <p class="mt-3 leading-7 text-slate-300">Projects are private to your account and ready to hold generated content as your work grows.</p>
        </div>
    </section>
@endsection
