<article class="px-5 py-8 sm:px-10 sm:py-12" aria-labelledby="article-headline">
    @if (is_string($content['publication'] ?? null) && $content['publication'] !== '')
        <header class="border-b-4 border-double border-slate-900 pb-5 text-center">
            <p class="break-words font-serif text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">{{ $content['publication'] }}</p>
            <p class="mt-2 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Fictional publication</p>
        </header>
    @endif

    <div class="mx-auto max-w-3xl py-8 sm:py-10">
        @if (is_string($content['headline'] ?? null) && $content['headline'] !== '')
            <h1 id="article-headline" class="break-words font-serif text-3xl font-bold leading-tight tracking-tight text-slate-950 sm:text-5xl">{{ $content['headline'] }}</h1>
        @else
            <h1 id="article-headline" class="font-serif text-3xl font-bold leading-tight tracking-tight text-slate-950 sm:text-5xl">News article</h1>
        @endif

        @if (is_string($content['published_at'] ?? null) && $content['published_at'] !== '')
            <p class="mt-5 text-sm text-slate-600">
                <span class="font-semibold text-slate-800">Published</span>
                <time class="ml-1 break-words" datetime="{{ $content['published_at'] }}">{{ $content['published_at'] }}</time>
            </p>
        @endif

        @if (is_string($content['body'] ?? null) && $content['body'] !== '')
            <div class="mt-8 border-t border-slate-200 pt-7 sm:mt-10 sm:pt-9">
                <p class="whitespace-pre-wrap break-words font-serif text-lg leading-8 text-slate-800 sm:text-xl sm:leading-9">{{ $content['body'] }}</p>
            </div>
        @endif
    </div>
</article>
