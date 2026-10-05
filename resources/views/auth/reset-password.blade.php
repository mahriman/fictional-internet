@extends('layouts.app')

@section('title', 'Reset password · Fictional Internet')

@section('content')
    <section class="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">Account recovery</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Choose a new password</h1>

        <form method="POST" action="{{ route('password.update') }}" class="mt-8 space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                <input id="email" name="email" type="email" autocomplete="email" required maxlength="255" value="{{ old('email', request()->query('email')) }}" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('email')<p class="mt-2 text-sm text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('password')<p class="mt-2 text-sm text-rose-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-slate-700">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            </div>
            <button type="submit" class="w-full rounded-lg bg-indigo-700 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Reset password</button>
        </form>
    </section>
@endsection
