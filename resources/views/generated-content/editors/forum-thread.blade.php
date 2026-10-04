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

    @php($sourcePosts = is_array($content['posts'] ?? null) ? array_values($content['posts']) : [])
    @php($submittedPosts = old('content.posts'))
    <fieldset class="space-y-5 border-t border-slate-100 pt-6">
        <legend class="text-lg font-semibold text-slate-950">Posts</legend>
        @error('content.posts')
            <p class="text-sm font-medium text-rose-700">{{ $message }}</p>
        @enderror
        @foreach ($sourcePosts as $index => $originalPost)
            @php($originalPost = is_array($originalPost) ? $originalPost : [])
            @php($post = is_array($submittedPosts[$index] ?? null) ? $submittedPosts[$index] : [])
            @php($postNumber = is_int($originalPost['post_number'] ?? null) ? $originalPost['post_number'] : $index + 1)
            @php($replyTargetValue = $post['reply_to_post_number'] ?? $originalPost['reply_to_post_number'] ?? '')
            @php($replyTarget = is_int($replyTargetValue) || is_string($replyTargetValue) ? $replyTargetValue : '')
            @php($quote = is_array($post['quote'] ?? null) ? $post['quote'] : (is_array($originalPost['quote'] ?? null) ? $originalPost['quote'] : []))
            <section class="space-y-4 rounded-xl border border-slate-200 p-4 sm:p-5" aria-labelledby="post-heading-{{ $index }}">
                <h2 id="post-heading-{{ $index }}" class="font-semibold text-slate-900">Post {{ $postNumber }}</h2>
                @foreach (['author' => ['Author', 80], 'posted_at' => ['Posted time', null]] as $field => [$label, $maximum])
                    @php($value = $post[$field] ?? $originalPost[$field] ?? '')
                    <div>
                        <label for="content_posts_{{ $index }}_{{ $field }}" class="block text-sm font-medium text-slate-700">{{ $label }} <span class="text-rose-700">*</span></label>
                        <input id="content_posts_{{ $index }}_{{ $field }}" name="content[posts][{{ $index }}][{{ $field }}]" type="text" value="{{ is_string($value) ? $value : '' }}" @if ($maximum !== null) maxlength="{{ $maximum }}" @endif required aria-invalid="{{ $errors->has('content.posts.'.$index.'.'.$field) ? 'true' : 'false' }}" @if ($field === 'posted_at') placeholder="2025-06-15T10:30:00+00:00" @endif class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                        @error('content.posts.'.$index.'.'.$field)
                            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
                @php($body = $post['body'] ?? $originalPost['body'] ?? '')
                <div>
                    <label for="content_posts_{{ $index }}_body" class="block text-sm font-medium text-slate-700">Post body <span class="text-rose-700">*</span></label>
                    <textarea id="content_posts_{{ $index }}_body" name="content[posts][{{ $index }}][body]" rows="6" maxlength="5000" required aria-invalid="{{ $errors->has('content.posts.'.$index.'.body') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 leading-7 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ is_string($body) ? $body : '' }}</textarea>
                    @error('content.posts.'.$index.'.body')
                        <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                @if ($index === 0)
                    <p class="text-sm text-slate-500">The opening post cannot reply to or quote another post.</p>
                @else
                    <div>
                        <label for="content_posts_{{ $index }}_reply_to_post_number" class="block text-sm font-medium text-slate-700">Reply to (optional)</label>
                        <select id="content_posts_{{ $index }}_reply_to_post_number" name="content[posts][{{ $index }}][reply_to_post_number]" aria-invalid="{{ $errors->has('content.posts.'.$index.'.reply_to_post_number') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                            <option value="">No reply target</option>
                            @foreach (array_slice($sourcePosts, 0, $index) as $targetIndex => $targetPost)
                                @php($targetPostNumber = is_array($targetPost) && is_int($targetPost['post_number'] ?? null) ? $targetPost['post_number'] : $targetIndex + 1)
                                @php($targetAuthor = is_array($targetPost) && is_string($targetPost['author'] ?? null) ? $targetPost['author'] : 'Unknown author')
                                <option value="{{ $targetPostNumber }}" @selected((string) $replyTarget === (string) $targetPostNumber)>#{{ $targetPostNumber }} · {{ $targetAuthor }}</option>
                            @endforeach
                        </select>
                        @error('content.posts.'.$index.'.reply_to_post_number')
                            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>

                    @php($quoteTargetValue = $quote['post_number'] ?? '')
                    @php($quoteTarget = is_int($quoteTargetValue) || is_string($quoteTargetValue) ? $quoteTargetValue : '')
                    <fieldset class="space-y-3 rounded-lg bg-slate-50 p-4">
                        <legend class="px-1 text-sm font-medium text-slate-700">Quote an earlier post (optional)</legend>
                        <div>
                            <label for="content_posts_{{ $index }}_quote_post_number" class="block text-sm font-medium text-slate-700">Quote source</label>
                            <select id="content_posts_{{ $index }}_quote_post_number" name="content[posts][{{ $index }}][quote][post_number]" aria-invalid="{{ $errors->has('content.posts.'.$index.'.quote.post_number') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option value="">No quote</option>
                                @foreach (array_slice($sourcePosts, 0, $index) as $targetIndex => $targetPost)
                                    @php($targetPostNumber = is_array($targetPost) && is_int($targetPost['post_number'] ?? null) ? $targetPost['post_number'] : $targetIndex + 1)
                                    @php($targetAuthor = is_array($targetPost) && is_string($targetPost['author'] ?? null) ? $targetPost['author'] : 'Unknown author')
                                    <option value="{{ $targetPostNumber }}" @selected((string) $quoteTarget === (string) $targetPostNumber)>#{{ $targetPostNumber }} · {{ $targetAuthor }}</option>
                                @endforeach
                            </select>
                            @error('content.posts.'.$index.'.quote.post_number')
                                <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                            @enderror
                        </div>
                        @php($quoteText = $quote['text'] ?? '')
                        <div>
                            <label for="content_posts_{{ $index }}_quote_text" class="block text-sm font-medium text-slate-700">Exact quoted text</label>
                            <textarea id="content_posts_{{ $index }}_quote_text" name="content[posts][{{ $index }}][quote][text]" rows="3" maxlength="5000" aria-describedby="content_posts_{{ $index }}_quote_help" aria-invalid="{{ $errors->has('content.posts.'.$index.'.quote.text') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 leading-6 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ is_string($quoteText) ? $quoteText : '' }}</textarea>
                            <p id="content_posts_{{ $index }}_quote_help" class="mt-2 text-xs leading-5 text-slate-500">The quote must be a non-empty, exact continuous excerpt from the selected post's final body. Clear both fields to remove a quote.</p>
                            @error('content.posts.'.$index.'.quote.text')
                                <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                            @enderror
                            @error('content.posts.'.$index.'.quote')
                                <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                            @enderror
                        </div>
                    </fieldset>
                @endif
            </section>
        @endforeach
    </fieldset>

    <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
        <a href="{{ route('projects.generated-content.versions.show', [$project, $generatedContent, $version->version_number]) }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Cancel</a>
        <button type="submit" class="rounded-lg bg-indigo-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Save as new version</button>
    </div>
</form>
