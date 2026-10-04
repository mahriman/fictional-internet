@php($messages = is_array($content['messages'] ?? null) ? array_values($content['messages']) : [])
<article class="overflow-hidden bg-slate-950 text-slate-100" aria-labelledby="schrecknet-thread-title">
    <header class="border-b border-emerald-900/70 bg-slate-900 px-5 py-7 sm:px-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="font-mono text-xs font-semibold uppercase tracking-[0.22em] text-emerald-400">{{ is_string($content['network'] ?? null) ? $content['network'] : 'SchreckNet' }} <span class="text-slate-500">/</span> {{ is_string($content['channel'] ?? null) ? $content['channel'] : 'unknown channel' }}</p>
                <h1 id="schrecknet-thread-title" class="mt-4 break-words font-mono text-2xl font-semibold leading-tight text-slate-50 sm:text-3xl">{{ is_string($content['thread_title'] ?? null) ? $content['thread_title'] : 'Untitled thread' }}</h1>
            </div>
            @if (is_string($content['started_at'] ?? null))
                <time class="shrink-0 font-mono text-xs text-amber-300" datetime="{{ $content['started_at'] }}">{{ $content['started_at'] }}</time>
            @endif
        </div>
        <p class="mt-5 font-mono text-xs text-slate-400">{{ count($messages) }} {{ count($messages) === 1 ? 'message' : 'messages' }} <span aria-hidden="true">·</span> encrypted channel view</p>
    </header>

    <ol class="divide-y divide-slate-800" aria-label="SchreckNet messages">
        @foreach ($messages as $index => $message)
            @if (is_array($message))
                @php($messageNumber = is_int($message['message_number'] ?? null) ? $message['message_number'] : $index + 1)
                @php($replyTarget = is_int($message['reply_to_message_number'] ?? null) ? $message['reply_to_message_number'] : null)
                @php($quote = is_array($message['quote'] ?? null) ? $message['quote'] : null)
                @php($quoteTarget = is_int($quote['message_number'] ?? null) ? $quote['message_number'] : null)
                @php($replyTargetIndex = null)
                @php($quoteTargetIndex = null)
                @foreach (array_slice($messages, 0, $index) as $earlierIndex => $earlierMessage)
                    @if (is_array($earlierMessage) && ($earlierMessage['message_number'] ?? null) === $replyTarget)
                        @php($replyTargetIndex = $earlierIndex)
                    @endif
                    @if (is_array($earlierMessage) && ($earlierMessage['message_number'] ?? null) === $quoteTarget)
                        @php($quoteTargetIndex = $earlierIndex)
                    @endif
                @endforeach
                <li class="px-5 py-5 sm:px-8" id="schrecknet-message-{{ $index + 1 }}">
                    <article aria-labelledby="schrecknet-message-{{ $index + 1 }}-handle">
                        <header class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                            <h2 id="schrecknet-message-{{ $index + 1 }}-handle" class="break-words font-mono text-sm font-semibold text-emerald-300">
                                <span class="mr-2 text-xs font-normal text-slate-500">[{{ $messageNumber }}]</span>{{ is_string($message['handle'] ?? null) ? $message['handle'] : 'unknown-handle' }}
                            </h2>
                            @if (is_string($message['posted_at'] ?? null))
                                <time class="break-words font-mono text-xs text-slate-500" datetime="{{ $message['posted_at'] }}">{{ $message['posted_at'] }}</time>
                            @endif
                        </header>

                        @if ($replyTarget !== null)
                            <p class="mt-2 font-mono text-xs text-slate-400">
                                @if ($replyTargetIndex !== null)
                                    Reply → <a href="#schrecknet-message-{{ $replyTargetIndex + 1 }}" class="text-amber-300 underline decoration-amber-700 underline-offset-2">[{{ $replyTarget }}]</a>
                                @else
                                    Reply → [{{ $replyTarget }}]
                                @endif
                            </p>
                        @endif

                        @if ($quote !== null && is_string($quote['text'] ?? null))
                            <blockquote class="mt-3 border-l-2 border-amber-500/70 bg-slate-900/80 px-4 py-3">
                                <p class="font-mono text-xs font-semibold text-amber-300">
                                    @if ($quoteTargetIndex !== null)
                                        @php($sourceHandle = $messages[$quoteTargetIndex]['handle'] ?? null)
                                        Quote from @if (is_string($sourceHandle)){{ $sourceHandle }} @endif<a href="#schrecknet-message-{{ $quoteTargetIndex + 1 }}" class="underline decoration-amber-700 underline-offset-2">[{{ $quoteTarget }}]</a>
                                    @else
                                        Quote from message [{{ $quoteTarget ?? '?' }}]
                                    @endif
                                </p>
                                <p class="mt-2 whitespace-pre-wrap break-words font-mono text-sm leading-6 text-slate-300">{{ $quote['text'] }}</p>
                            </blockquote>
                        @endif

                        @if (is_string($message['body'] ?? null))
                            <p class="mt-3 whitespace-pre-wrap break-words font-mono text-sm leading-7 text-slate-200">{{ $message['body'] }}</p>
                        @endif
                    </article>
                </li>
            @endif
        @endforeach
    </ol>
</article>
