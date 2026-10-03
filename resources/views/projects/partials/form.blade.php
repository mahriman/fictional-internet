<form method="POST" action="{{ $action }}" class="mt-8 space-y-6">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div>
        <label for="name" class="block text-sm font-medium text-slate-700">Project name <span class="text-rose-700">*</span></label>
        <input id="name" name="name" type="text" required maxlength="255" value="{{ old('name', $project?->name) }}" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" aria-describedby="name-help" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
        <p id="name-help" class="mt-2 text-xs text-slate-500">Use up to 255 characters.</p>
        @error('name')
            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="description" class="block text-sm font-medium text-slate-700">Description <span class="font-normal text-slate-500">(optional)</span></label>
        <textarea id="description" name="description" rows="5" aria-invalid="{{ $errors->has('description') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ old('description', $project?->description) }}</textarea>
        @error('description')
            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
        @enderror
    </div>

    <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
        <a href="{{ $project ? route('projects.show', $project) : route('projects.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Cancel</a>
        <button type="submit" class="rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">{{ $buttonText }}</button>
    </div>
</form>
