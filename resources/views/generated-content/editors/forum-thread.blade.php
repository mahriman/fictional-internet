<form method="POST" action="{{ route('projects.generated-content.versions.edits.store', [$project, $generatedContent, $version->version_number]) }}" class="mt-8 space-y-6">
    @csrf

    @foreach ([
        'forum_name' => ['Forum name', 255],
        'thread_title' => ['Thread title', 255],
        'category' => ['Category', 120],
        'started_at' => ['Thread start time', null],
    ] as $field => [$label, $maximum])
        @php($value = old('content.'.$field, $content[$field] ?? ''))
        <div>
            <label for="content_{{ $field }}" class="block text-sm font-medium text-slate-700">{{ $label }} <span class="text-rose-700">*</span></label>
            <input id="content_{{ $field }}" name="content[{{ $field }}]" type="text" value="{{ is_string($value) ? $value : '' }}" @if ($maximum !== null) maxlength="{{ $maximum }}" @endif required aria-invalid="{{ $errors->has('content.'.$field) ? 'true' : 'false' }}" @if ($field === 'started_at') placeholder="2025-06-15T10:30:00+00:00" aria-describedby="content_started_at_help" @endif class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            @if ($field === 'started_at')
                <p id="content_started_at_help" class="mt-2 text-xs text-slate-500">Use YYYY-MM-DDTHH:MM:SS±HH:MM, including the numeric timezone offset.</p>
            @endif
            @error('content.'.$field)
                <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
            @enderror
        </div>
    @endforeach

    @php($posts = is_array(old('content.posts')) ? old('content.posts') : (is_array($content['posts'] ?? null) ? array_values($content['posts']) : []))
    <fieldset class="space-y-5 border-t border-slate-100 pt-6">
        <legend class="text-lg font-semibold text-slate-950">Posts</legend>
        @error('content.posts')
            <p class="text-sm font-medium text-rose-700">{{ $message }}</p>
        @enderror
        @foreach ($posts as $index => $post)
            @php($post = is_array($post) ? $post : [])
            @php($originalPost = is_array(($content['posts'] ?? [])[$index] ?? null) ? $content['posts'][$index] : [])
            <section class="rounded-xl border border-slate-200 p-4 sm:p-5" aria-labelledby="post-heading-{{ $index }}">
                <h2 id="post-heading-{{ $index }}" class="font-semibold text-slate-900">Post {{ is_int($originalPost['post_number'] ?? null) ? $originalPost['post_number'] : $index + 1 }}</h2>
                @foreach (['author' => ['Author', 80], 'posted_at' => ['Posted time', null]] as $field => [$label, $maximum])
                    @php($value = $post[$field] ?? $originalPost[$field] ?? '')
                    <div class="mt-4">
                        <label for="content_posts_{{ $index }}_{{ $field }}" class="block text-sm font-medium text-slate-700">{{ $label }} <span class="text-rose-700">*</span></label>
                        <input id="content_posts_{{ $index }}_{{ $field }}" name="content[posts][{{ $index }}][{{ $field }}]" type="text" value="{{ is_string($value) ? $value : '' }}" @if ($maximum !== null) maxlength="{{ $maximum }}" @endif required aria-invalid="{{ $errors->has('content.posts.'.$index.'.'.$field) ? 'true' : 'false' }}" @if ($field === 'posted_at') placeholder="2025-06-15T10:30:00+00:00" @endif class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                        @error('content.posts.'.$index.'.'.$field)
                            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
                @php($body = $post['body'] ?? $originalPost['body'] ?? '')
                <div class="mt-4">
                    <label for="content_posts_{{ $index }}_body" class="block text-sm font-medium text-slate-700">Post body <span class="text-rose-700">*</span></label>
                    <textarea id="content_posts_{{ $index }}_body" name="content[posts][{{ $index }}][body]" rows="6" maxlength="5000" required aria-invalid="{{ $errors->has('content.posts.'.$index.'.body') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 leading-7 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ is_string($body) ? $body : '' }}</textarea>
                    @error('content.posts.'.$index.'.body')
                        <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </div>
            </section>
        @endforeach
    </fieldset>

    <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
        <a href="{{ route('projects.generated-content.versions.show', [$project, $generatedContent, $version->version_number]) }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Cancel</a>
        <button type="submit" class="rounded-lg bg-indigo-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Save as new version</button>
    </div>
</form>
