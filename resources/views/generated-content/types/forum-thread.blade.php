@php($posts = is_array($content['posts'] ?? null) ? array_values($content['posts']) : [])
<article class="px-5 py-7 sm:px-8 sm:py-9" aria-labelledby="forum-thread-title">
    <header class="border-b border-slate-200 pb-6">
        <p class="break-words text-sm font-semibold uppercase tracking-wide text-indigo-700">{{ is_string($content['forum_name'] ?? null) ? $content['forum_name'] : 'Forum' }}</p>
        <p class="mt-1 break-words text-xs font-medium uppercase tracking-wider text-slate-500">{{ is_string($content['category'] ?? null) ? $content['category'] : 'Uncategorized' }}</p>
        <h1 id="forum-thread-title" class="mt-4 break-words text-3xl font-bold leading-tight tracking-tight text-slate-950 sm:text-4xl">{{ is_string($content['thread_title'] ?? null) ? $content['thread_title'] : 'Forum thread' }}</h1>
        @if (is_string($content['started_at'] ?? null))
            <p class="mt-3 text-sm text-slate-500">Started <time datetime="{{ $content['started_at'] }}">{{ $content['started_at'] }}</time></p>
        @endif
    </header>

    <ol class="divide-y divide-slate-200" aria-label="Thread posts">
        @foreach ($posts as $index => $post)
            @if (is_array($post))
                @php($postNumber = is_int($post['post_number'] ?? null) ? $post['post_number'] : $index + 1)
                @php($replyTarget = is_int($post['reply_to_post_number'] ?? null) ? $post['reply_to_post_number'] : null)
                @php($quote = is_array($post['quote'] ?? null) ? $post['quote'] : null)
                @php($quoteTarget = is_int($quote['post_number'] ?? null) ? $quote['post_number'] : null)
                @php($replyTargetIndex = null)
                @php($quoteTargetIndex = null)
                @foreach (array_slice($posts, 0, $index) as $earlierIndex => $earlierPost)
                    @if (is_array($earlierPost) && ($earlierPost['post_number'] ?? null) === $replyTarget)
                        @php($replyTargetIndex = $earlierIndex)
                    @endif
                    @if (is_array($earlierPost) && ($earlierPost['post_number'] ?? null) === $quoteTarget)
                        @php($quoteTargetIndex = $earlierIndex)
                    @endif
                @endforeach
                <li class="py-6 first:pt-6 last:pb-0">
                    <article id="forum-post-{{ $index + 1 }}" aria-labelledby="forum-post-{{ $index }}-author">
                        <header class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                            <h2 id="forum-post-{{ $index }}-author" class="break-words font-semibold text-slate-900">
                                <span class="mr-2 text-sm font-medium text-slate-400">#{{ $postNumber }}</span>{{ is_string($post['author'] ?? null) ? $post['author'] : 'Unknown author' }}
                            </h2>
                            @if (is_string($post['posted_at'] ?? null))
                                <time class="break-words text-xs text-slate-500" datetime="{{ $post['posted_at'] }}">{{ $post['posted_at'] }}</time>
                            @endif
                        </header>
                        @if ($replyTarget !== null)
                            <p class="mt-2 text-xs font-medium text-slate-500">
                                @if ($replyTargetIndex !== null)
                                    Replying to <a href="#forum-post-{{ $replyTargetIndex + 1 }}" class="text-indigo-700 underline decoration-indigo-300 underline-offset-2">#{{ $replyTarget }}</a>
                                @else
                                    Replying to #{{ $replyTarget }}
                                @endif
                            </p>
                        @endif
                        @if ($quote !== null && is_string($quote['text'] ?? null))
                            <blockquote class="mt-3 rounded-r-lg border-l-4 border-indigo-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    @if ($quoteTargetIndex !== null)
                                        Quoting <a href="#forum-post-{{ $quoteTargetIndex + 1 }}" class="text-indigo-700 underline decoration-indigo-300 underline-offset-2">#{{ $quoteTarget }}</a>
                                        @php($quoteAuthor = $posts[$quoteTargetIndex]['author'] ?? null)
                                        @if (is_string($quoteAuthor))
                                            <span>by {{ $quoteAuthor }}</span>
                                        @endif
                                    @else
                                        Quote from #{{ $quoteTarget ?? '?' }}
                                    @endif
                                </p>
                                <p class="mt-2 whitespace-pre-wrap break-words">{{ $quote['text'] }}</p>
                            </blockquote>
                        @endif
                        @if (is_string($post['body'] ?? null))
                            <p class="mt-3 whitespace-pre-wrap break-words text-sm leading-7 text-slate-700">{{ $post['body'] }}</p>
                        @endif
                    </article>
                </li>
            @endif
        @endforeach
    </ol>
</article>
