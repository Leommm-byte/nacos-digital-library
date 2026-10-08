{{--
    The floating assistant button and chat panel on signed-in pages. The
    button is a link to the full page, so it works without JavaScript;
    resources/js/assistant.js opens the panel instead and loads the chat.
--}}
<div class="assistant" data-assistant data-url="{{ route('assistant.index') }}">
    <a href="{{ route('assistant.index') }}" class="assistant-fab" data-assistant-open aria-controls="assistant-panel" aria-expanded="false">
        <x-icon name="message-circle" />
        <span>Ask</span>
        <span class="sr-only">the assistant</span>
    </a>
    <section id="assistant-panel" class="assistant-panel" role="dialog" aria-labelledby="assistant-title" hidden>
        @include('assistant.partials.chat', [
            'messages' => [],
            'ai' => \App\Support\Assistant\Assistant::aiEnabled(),
            'remaining' => null,
            'suggestions' => \App\Support\Assistant\Helper::MENU,
            'panel' => true,
        ])
    </section>
</div>
