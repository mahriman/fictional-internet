<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', config('app.name', 'Fictional Internet'))</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-6 px-4 py-4 sm:px-6 lg:px-8">
                <a href="{{ route('home') }}" class="flex items-center gap-3 rounded-md font-semibold tracking-tight text-slate-950 focus:outline-2 focus:outline-offset-4 focus:outline-indigo-700">
                    <span class="grid size-9 place-items-center rounded-lg bg-indigo-700 text-sm font-bold text-white">FI</span>
                    <span>Fictional Internet</span>
                </a>
                <nav aria-label="Main navigation" class="flex items-center gap-3 sm:gap-5">
                    @auth
                        <a href="{{ route('projects.index') }}" class="text-sm font-medium text-slate-700 hover:text-indigo-700">Projects</a>
                        <a href="{{ route('account.settings') }}" class="text-sm font-medium text-slate-700 hover:text-indigo-700">Account settings</a>
                        <span class="hidden text-sm text-slate-500 sm:inline">{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="rounded-md px-2 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Sign out</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="text-sm font-medium text-slate-700 hover:text-indigo-700">Sign in</a>
                        <a href="{{ route('register') }}" class="rounded-lg bg-indigo-700 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Create account</a>
                    @endauth
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
            @if (session('status'))
                <div role="status" class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div role="alert" class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    <p class="font-semibold">Please review the following:</p>
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-1 px-4 py-4 text-sm text-slate-600 sm:px-6 lg:px-8">
                <span>Fictional Internet</span>
                <span aria-hidden="true">·</span>
                <a href="{{ route('license') }}" class="font-medium underline underline-offset-2 hover:text-indigo-700">AGPLv3 license</a>
                <span aria-hidden="true">·</span>
                <a href="{{ route('license') }}#source" class="font-medium underline underline-offset-2 hover:text-indigo-700">Source</a>
            </div>
        </footer>
    </body>
</html>
