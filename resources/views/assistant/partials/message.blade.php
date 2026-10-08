{{-- One chat message, drawn from text blocks (App\Support\Assistant\Format); resources/js/assistant.js draws the same markup. --}}
@php
    $blocks = \App\Support\Assistant\Format::blocks($message->body);
    $bot = $message->role === 'assistant';
@endphp

<div @class(['assistant-msg', 'is-bot' => $bot, 'is-user' => ! $bot]) @if ($latest ?? false) id="latest" @endif>
    <span class="sr-only">{{ $bot ? 'Assistant:' : 'You:' }}</span>
    <div class="assistant-bubble">
        @foreach ($blocks as $block)
            @if ($block['type'] === 'p')
                @foreach ($block['items'] as $item)
                    <p>{{ \App\Support\Assistant\Format::html($item) }}</p>
                @endforeach
            @else
                <{{ $block['type'] }}>
                    @foreach ($block['items'] as $item)
                        <li>{{ \App\Support\Assistant\Format::html($item) }}</li>
                    @endforeach
                </{{ $block['type'] }}>
            @endif
        @endforeach
    </div>
    @if ($bot && $message->links)
        <ul class="assistant-links">
            @foreach ($message->links as $link)
                <li>
                    <a href="{{ $link['url'] }}">
                        <span class="assistant-link-label">{{ $link['label'] }}</span>
                        @if (! empty($link['note']))
                            <span class="assistant-link-note">{{ $link['note'] }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
