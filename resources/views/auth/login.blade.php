@extends('layouts.app')

@section('title', 'Sign in · Fictional Internet')

@section('content')
    <section class="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">Welcome back</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Sign in</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">Continue working on your projects.</p>

        <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
            @csrf
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-indigo-700 focus:ring-indigo-700">
                Remember me
            </label>
            <button type="submit" class="w-full rounded-lg bg-indigo-700 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Sign in</button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-600">New here? <a href="{{ route('register') }}" class="font-semibold text-indigo-700 hover:text-indigo-900">Create an account</a></p>
    </section>
@endsection
