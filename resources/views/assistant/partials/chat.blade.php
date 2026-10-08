{{--
    The chat: header, messages and the question form. Used by the floating
    panel and by the full page (no JavaScript, or a direct visit). Expects
    $messages (may be empty, filled by assistant.js), $ai, $remaining,
    $suggestions, $panel.
--}}
@php
    $status = match (true) {
        ! $ai => 'Quick answers about the library and your account',
        $remaining === null => 'Smart answers on',
        $remaining > 0 => 'Smart answers on · '.$remaining.' left today',
        default => 'Quick answers until tomorrow',
    };
@endphp

<div class="assistant-head">
    <x-icon-tile name="bot" tone="green" size="sm" />
    <div class="min-w-0 flex-1">
        <h2 id="assistant-title" class="font-display font-bold">Assistant</h2>
        <p class="truncate text-xs text-muted" data-assistant-status>{{ $status }}</p>
    </div>
    <form method="POST" action="{{ route('assistant.destroy') }}" data-assistant-clear data-confirm="Clear this chat?">
        @csrf
        @method('DELETE')
        <button class="btn btn-ghost btn-icon" aria-label="Clear chat" title="Clear chat"><x-icon name="eraser" /></button>
    </form>
    @if ($panel)
        <button type="button" class="btn btn-ghost btn-icon" data-assistant-close aria-label="Close the assistant"><x-icon name="x" /></button>
    @endif
</div>

<div class="assistant-log" data-assistant-log aria-live="polite">
    <div class="assistant-msg is-bot" data-assistant-intro>
        <span class="sr-only">Assistant:</span>
        <div class="assistant-bubble">
            <p>Hi {{ auth()->user()?->firstName() }}! Ask me to find books, check your uploads, saved books or notifications, or tell you about elections.@if ($ai) I can also explain a topic or quiz you from a library book.@endif</p>
        </div>
        <div class="assistant-chips" @if (count($messages)) hidden @endif>
            @foreach ($suggestions as $suggestion)
                <form method="POST" action="{{ route('assistant.store') }}">
                    @csrf
                    <input type="hidden" name="message" value="{{ $suggestion }}">
                    <button class="assistant-chip">{{ $suggestion }}</button>
                </form>
            @endforeach
        </div>
    </div>

    @foreach ($messages as $message)
        @include('assistant.partials.message', ['message' => $message, 'latest' => $loop->last])
    @endforeach
</div>

<form method="POST" action="{{ route('assistant.store') }}" class="assistant-form" data-assistant-form novalidate>
    @csrf
    <label for="assistant-input-{{ $panel ? 'panel' : 'page' }}" class="sr-only">Your question</label>
    <textarea id="assistant-input-{{ $panel ? 'panel' : 'page' }}" name="message" rows="1" maxlength="1000" required
        placeholder="{{ $ai ? 'Ask about a book, a topic or your account' : 'Ask about books, uploads or elections' }}" enterkeyhint="send">{{ $panel ? '' : old('message') }}</textarea>
    <button class="btn btn-primary btn-icon" aria-label="Send"><x-icon name="send" /></button>
</form>
@if (! $panel)
    @error('message')
        <p class="field-error px-4 pb-3">{{ $message }}</p>
    @enderror
@endif
