@php
    $subtitle = $ai
        ? 'Find books, check your account, or get help with a topic you’re studying.'
        : 'Find books and check your uploads, saved books, notifications and elections.';
@endphp

<x-layouts.app title="Assistant" description="Ask about books, your uploads, elections or a topic you’re studying.">
    <x-page-header title="Assistant" :subtitle="$subtitle" />

    <div class="assistant-page" data-assistant data-inline data-url="{{ route('assistant.index') }}">
        <section class="assistant-panel is-inline" aria-labelledby="assistant-title">
            @include('assistant.partials.chat', ['panel' => false])
        </section>

        <aside class="assistant-aside">
            <x-card>
                <h2 class="font-display font-bold">Good to know</h2>
                <ul class="mt-3 space-y-3 text-sm text-muted">
                    <li class="flex gap-2"><x-icon name="lock" class="mt-0.5 shrink-0 text-link" /> The assistant only sees the library and your own account. It can't change anything for you.</li>
                    @if ($ai)
                        <li class="flex gap-2"><x-icon name="sparkles" class="mt-0.5 shrink-0 text-link" /> Smart answers use AI and can be wrong. Check important details in the book itself.</li>
                        <li class="flex gap-2"><x-icon name="book-open" class="mt-0.5 shrink-0 text-link" /> It helps you learn, so it explains and gives examples rather than writing assignments for you.</li>
                    @endif
                    <li class="flex gap-2"><x-icon name="eraser" class="mt-0.5 shrink-0 text-link" /> Your last {{ config('assistant.history') }} messages are kept so you can pick up later. Clear them any time.</li>
                </ul>
            </x-card>
        </aside>
    </div>
</x-layouts.app>
