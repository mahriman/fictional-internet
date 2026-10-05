@extends('layouts.app')

@section('title', 'Account settings')

@section('content')
    <section class="mx-auto max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">Account</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Settings</h1>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="profile-heading">
            <div class="border-b border-slate-100 pb-5">
                <h2 id="profile-heading" class="text-xl font-semibold text-slate-950">Profile</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">Your name and email address identify your account. Email is used to sign in.</p>
            </div>

            <form method="POST" action="{{ route('account.settings.profile.update') }}" class="mt-6 space-y-4">
                @csrf
                @method('PATCH')
                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700">Name</label>
                    <input id="name" name="name" type="text" autocomplete="name" maxlength="255" required value="{{ old('name', $user->name) }}" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                    @error('name')<p class="mt-2 text-sm text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="email" maxlength="255" required value="{{ old('email', $user->email) }}" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                    @error('email')<p class="mt-2 text-sm text-rose-700">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="rounded-lg bg-indigo-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Save profile</button>
            </form>
        </section>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="password-heading">
            <div class="border-b border-slate-100 pb-5">
                <h2 id="password-heading" class="text-xl font-semibold text-slate-950">Password</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">Confirm your current password before choosing a new one.</p>
            </div>
            <form method="POST" action="{{ route('account.settings.password.update') }}" class="mt-6 space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label for="current_password" class="block text-sm font-medium text-slate-700">Current password</label>
                    <input id="current_password" name="current_password" type="password" autocomplete="current-password" required aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                    @error('current_password')<p class="mt-2 text-sm text-rose-700">{{ $message }}</p>@enderror
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
                <button type="submit" class="rounded-lg bg-indigo-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Change password</button>
            </form>
        </section>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="openai-key-heading">
            <div class="border-b border-slate-100 pb-5">
                <h2 id="openai-key-heading" class="text-xl font-semibold text-slate-950">OpenAI API key</h2>
                @if ($hasPersonalKey)
                    <p class="mt-2 text-sm font-medium text-emerald-800">A personal key is configured. For security, the saved key is never shown again.</p>
                @else
                    <p class="mt-2 text-sm text-slate-600">No personal key is configured.</p>
                @endif
                <p class="mt-3 text-sm leading-6 text-slate-600">A personal key is required for generation. The application does not use a shared server key as a fallback.</p>
            </div>

            <form method="POST" action="{{ route('account.settings.openai-credential.store') }}" class="mt-6 space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="api_key" class="block text-sm font-medium text-slate-700">{{ $hasPersonalKey ? 'Replace personal API key' : 'Personal API key' }}</label>
                    <input id="api_key" name="api_key" type="password" autocomplete="new-password" required maxlength="512" aria-describedby="api-key-help" aria-invalid="{{ $errors->has('api_key') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                    <p id="api-key-help" class="mt-2 text-xs leading-5 text-slate-500">Enter a key to save or replace it. The field is always blank when this page opens.</p>
                    @error('api_key')
                        <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="rounded-lg bg-indigo-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">{{ $hasPersonalKey ? 'Replace key' : 'Save key' }}</button>
            </form>

            @if ($hasPersonalKey)
                <form method="POST" action="{{ route('account.settings.openai-credential.destroy') }}" class="mt-6 border-t border-slate-100 pt-5" onsubmit="return confirm('Remove your saved personal OpenAI API key?')">
                    @csrf
                    @method('DELETE')
                    <p class="mb-3 text-sm leading-6 text-slate-600">Removing this key prevents you from starting new AI generations until you save another personal key. Existing content remains available.</p>
                    <button type="submit" class="rounded-lg border border-rose-300 px-4 py-2.5 text-sm font-semibold text-rose-800 hover:bg-rose-50 focus:outline-2 focus:outline-offset-2 focus:outline-rose-700">Remove personal key</button>
                </form>
            @endif
        </section>

        <section class="mt-6 rounded-2xl border border-rose-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="delete-account-heading">
            <div class="border-b border-rose-100 pb-5">
                <h2 id="delete-account-heading" class="text-xl font-semibold text-rose-950">Delete account</h2>
                <p class="mt-2 text-sm leading-6 text-slate-700">Deleting your account permanently removes your projects, project context, generated artifacts and their complete version histories, your OpenAI credential, and generation-attempt records. This cannot be undone.</p>
                <p class="mt-3 text-sm leading-6 text-slate-700">You can export each project's data before deleting your account. Project exports contain project data and version history; they are not a full account backup and exclude account/authentication data and credentials.</p>
                <a href="{{ route('projects.index') }}" class="mt-3 inline-flex text-sm font-semibold text-indigo-700 underline hover:text-indigo-900">Review projects and export project data</a>
            </div>
            <form method="POST" action="{{ route('account.settings.destroy') }}" class="mt-6 space-y-4">
                @csrf
                @method('DELETE')
                <div>
                    <label for="delete-current-password" class="block text-sm font-medium text-slate-700">Current password</label>
                    <input id="delete-current-password" name="current_password" type="password" autocomplete="current-password" required aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-200">
                    @error('current_password')<p class="mt-2 text-sm text-rose-700">{{ $message }}</p>@enderror
                </div>
                <label class="flex items-start gap-3 text-sm leading-6 text-slate-700">
                    <input type="checkbox" name="confirm_deletion" value="1" required class="mt-1 rounded border-slate-300 text-rose-700 focus:ring-rose-700">
                    <span>I understand that deleting my account permanently deletes its data.</span>
                </label>
                @error('confirm_deletion')<p class="text-sm text-rose-700">{{ $message }}</p>@enderror
                <button type="submit" class="rounded-lg border border-rose-400 bg-rose-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-rose-800 focus:outline-2 focus:outline-offset-2 focus:outline-rose-700">Permanently delete account</button>
            </form>
        </section>
    </section>
@endsection
