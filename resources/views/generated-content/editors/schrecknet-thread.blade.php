<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 font-mono text-sm text-emerald-950">
    Network identity: <span class="font-semibold">SchreckNet</span>. Message numbers and order are preserved from this version.
</div>

<form method="POST" action="{{ route('projects.generated-content.versions.edits.store', [$project, $generatedContent, $version->version_number]) }}" class="mt-8 space-y-6">
    @csrf

    @foreach ([
        'channel' => ['Channel', 255],
        'thread_title' => ['Thread title', 255],
        'started_at' => ['Thread start time', null],
    ] as $field => [$label, $maximum])
        @php($value = old('content.'.$field, $content[$field] ?? ''))
        <div>
            <label for="content_{{ $field }}" class="block text-sm font-medium text-slate-700">{{ $label }} <span class="text-rose-700">*</span></label>
            <input id="content_{{ $field }}" name="content[{{ $field }}]" type="text" value="{{ is_string($value) ? $value : '' }}" @if ($maximum !== null) maxlength="{{ $maximum }}" @endif required aria-invalid="{{ $errors->has('content.'.$field) ? 'true' : 'false' }}" @if ($field === 'started_at') placeholder="2025-06-15T10:30:00+00:00" aria-describedby="content_started_at_help" @endif class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            @if ($field === 'started_at')
                <p id="content_started_at_help" class="mt-2 text-xs text-slate-500">Use YYYY-MM-DDTHH:MM:SS±HH:MM with a numeric timezone offset.</p>
            @endif
            @error('content.'.$field)
                <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
            @enderror
        </div>
    @endforeach

    @php($sourceMessages = is_array($content['messages'] ?? null) ? array_values($content['messages']) : [])
    @php($submittedMessages = old('content.messages'))
    <fieldset class="space-y-5 border-t border-slate-100 pt-6">
        <legend class="text-lg font-semibold text-slate-950">Messages</legend>
        @error('content.messages')
            <p class="text-sm font-medium text-rose-700">{{ $message }}</p>
        @enderror
        @foreach ($sourceMessages as $index => $originalMessage)
            @php($originalMessage = is_array($originalMessage) ? $originalMessage : [])
            @php($message = is_array($submittedMessages[$index] ?? null) ? $submittedMessages[$index] : [])
            @php($messageNumber = is_int($originalMessage['message_number'] ?? null) ? $originalMessage['message_number'] : $index + 1)
            @php($replyValue = $message['reply_to_message_number'] ?? $originalMessage['reply_to_message_number'] ?? '')
            @php($replyTarget = is_int($replyValue) || is_string($replyValue) ? $replyValue : '')
            @php($quote = is_array($message['quote'] ?? null) ? $message['quote'] : (is_array($originalMessage['quote'] ?? null) ? $originalMessage['quote'] : []))
            <section class="space-y-4 rounded-xl border border-slate-200 p-4 sm:p-5" aria-labelledby="message-heading-{{ $index }}">
                <h2 id="message-heading-{{ $index }}" class="font-mono font-semibold text-slate-900">Message {{ $messageNumber }}</h2>

                @foreach (['handle' => ['Handle', 80], 'posted_at' => ['Posted time', null]] as $field => [$label, $maximum])
                    @php($value = $message[$field] ?? $originalMessage[$field] ?? '')
                    <div>
                        <label for="content_messages_{{ $index }}_{{ $field }}" class="block text-sm font-medium text-slate-700">{{ $label }} <span class="text-rose-700">*</span></label>
                        <input id="content_messages_{{ $index }}_{{ $field }}" name="content[messages][{{ $index }}][{{ $field }}]" type="text" value="{{ is_string($value) ? $value : '' }}" @if ($maximum !== null) maxlength="{{ $maximum }}" @endif required aria-invalid="{{ $errors->has('content.messages.'.$index.'.'.$field) ? 'true' : 'false' }}" @if ($field === 'posted_at') placeholder="2025-06-15T10:30:00+00:00" @endif class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                        @error('content.messages.'.$index.'.'.$field)
                            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                @php($body = $message['body'] ?? $originalMessage['body'] ?? '')
                <div>
                    <label for="content_messages_{{ $index }}_body" class="block text-sm font-medium text-slate-700">Message body <span class="text-rose-700">*</span></label>
                    <textarea id="content_messages_{{ $index }}_body" name="content[messages][{{ $index }}][body]" rows="5" maxlength="5000" required aria-invalid="{{ $errors->has('content.messages.'.$index.'.body') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 font-mono leading-7 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ is_string($body) ? $body : '' }}</textarea>
                    @error('content.messages.'.$index.'.body')
                        <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                @if ($index === 0)
                    <p class="text-sm text-slate-500">The opening message cannot reply to or quote an earlier message.</p>
                @else
                    <div>
                        <label for="content_messages_{{ $index }}_reply_to_message_number" class="block text-sm font-medium text-slate-700">Reply to (optional)</label>
                        <select id="content_messages_{{ $index }}_reply_to_message_number" name="content[messages][{{ $index }}][reply_to_message_number]" aria-invalid="{{ $errors->has('content.messages.'.$index.'.reply_to_message_number') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                            <option value="">No reply target</option>
                            @foreach (array_slice($sourceMessages, 0, $index) as $targetIndex => $targetMessage)
                                @php($targetNumber = is_array($targetMessage) && is_int($targetMessage['message_number'] ?? null) ? $targetMessage['message_number'] : $targetIndex + 1)
                                @php($targetHandle = is_array($targetMessage) && is_string($targetMessage['handle'] ?? null) ? $targetMessage['handle'] : 'unknown-handle')
                                <option value="{{ $targetNumber }}" @selected((string) $replyTarget === (string) $targetNumber)>[{{ $targetNumber }}] · {{ $targetHandle }}</option>
                            @endforeach
                        </select>
                        @error('content.messages.'.$index.'.reply_to_message_number')
                            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>

                    @php($quoteNumberValue = $quote['message_number'] ?? '')
                    @php($quoteNumber = is_int($quoteNumberValue) || is_string($quoteNumberValue) ? $quoteNumberValue : '')
                    <fieldset class="space-y-3 rounded-lg bg-slate-50 p-4">
                        <legend class="px-1 text-sm font-medium text-slate-700">Quote an earlier message (optional)</legend>
                        <div>
                            <label for="content_messages_{{ $index }}_quote_message_number" class="block text-sm font-medium text-slate-700">Quote source</label>
                            <select id="content_messages_{{ $index }}_quote_message_number" name="content[messages][{{ $index }}][quote][message_number]" aria-invalid="{{ $errors->has('content.messages.'.$index.'.quote.message_number') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option value="">No quote</option>
                                @foreach (array_slice($sourceMessages, 0, $index) as $targetIndex => $targetMessage)
                                    @php($targetNumber = is_array($targetMessage) && is_int($targetMessage['message_number'] ?? null) ? $targetMessage['message_number'] : $targetIndex + 1)
                                    @php($targetHandle = is_array($targetMessage) && is_string($targetMessage['handle'] ?? null) ? $targetMessage['handle'] : 'unknown-handle')
                                    <option value="{{ $targetNumber }}" @selected((string) $quoteNumber === (string) $targetNumber)>[{{ $targetNumber }}] · {{ $targetHandle }}</option>
                                @endforeach
                            </select>
                            @error('content.messages.'.$index.'.quote.message_number')
                                <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                            @enderror
                        </div>
                        @php($quoteText = $quote['text'] ?? '')
                        <div>
                            <label for="content_messages_{{ $index }}_quote_text" class="block text-sm font-medium text-slate-700">Exact quoted text</label>
                            <textarea id="content_messages_{{ $index }}_quote_text" name="content[messages][{{ $index }}][quote][text]" rows="3" maxlength="5000" aria-describedby="content_messages_{{ $index }}_quote_help" aria-invalid="{{ $errors->has('content.messages.'.$index.'.quote.text') ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 font-mono leading-6 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ is_string($quoteText) ? $quoteText : '' }}</textarea>
                            <p id="content_messages_{{ $index }}_quote_help" class="mt-2 text-xs leading-5 text-slate-500">The quote must be a non-empty, exact continuous excerpt from the selected message's final body. Clear both fields to remove a quote.</p>
                            @error('content.messages.'.$index.'.quote.text')
                                <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                            @enderror
                            @error('content.messages.'.$index.'.quote')
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
