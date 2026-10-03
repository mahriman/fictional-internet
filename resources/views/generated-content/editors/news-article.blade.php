<form method="POST" action="{{ route('projects.generated-content.versions.edits.store', [$project, $generatedContent, $version->version_number]) }}" class="mt-8 space-y-6">
    @csrf

    <div>
        <label for="content_publication" class="block text-sm font-medium text-slate-700">Publication <span class="text-rose-700">*</span></label>
        @php($publication = old('content.publication', $content['publication'] ?? ''))
        <input id="content_publication" name="content[publication]" type="text" value="{{ is_string($publication) ? $publication : '' }}" maxlength="255" required aria-invalid="{{ $errors->has('content.publication') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
        @error('content.publication')
            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="content_headline" class="block text-sm font-medium text-slate-700">Headline <span class="text-rose-700">*</span></label>
        @php($headline = old('content.headline', $content['headline'] ?? ''))
        <input id="content_headline" name="content[headline]" type="text" value="{{ is_string($headline) ? $headline : '' }}" maxlength="255" required aria-invalid="{{ $errors->has('content.headline') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
        @error('content.headline')
            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="content_published_at" class="block text-sm font-medium text-slate-700">Publication date <span class="text-rose-700">*</span></label>
        @php($publishedAt = old('content.published_at', $content['published_at'] ?? ''))
        <input id="content_published_at" name="content[published_at]" type="text" value="{{ is_string($publishedAt) ? $publishedAt : '' }}" placeholder="2025-06-15T10:30:00Z" required aria-describedby="content_published_at_help" aria-invalid="{{ $errors->has('content.published_at') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
        <p id="content_published_at_help" class="mt-2 text-xs text-slate-500">Keep the date and time format, including its timezone, when editing.</p>
        @error('content.published_at')
            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="content_body" class="block text-sm font-medium text-slate-700">Article body <span class="text-rose-700">*</span></label>
        @php($body = old('content.body', $content['body'] ?? ''))
        <textarea id="content_body" name="content[body]" rows="14" required aria-invalid="{{ $errors->has('content.body') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 leading-7 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ is_string($body) ? $body : '' }}</textarea>
        @error('content.body')
            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
        @enderror
    </div>

    <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
        <a href="{{ route('projects.generated-content.versions.show', [$project, $generatedContent, $version->version_number]) }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Cancel</a>
        <button type="submit" class="rounded-lg bg-indigo-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Save as new version</button>
    </div>
</form>
