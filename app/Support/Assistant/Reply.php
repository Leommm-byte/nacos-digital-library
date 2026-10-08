<?php

namespace App\Support\Assistant;

/**
 * An answer from the assistant: plain text (see Format), links to books
 * and pages in the app, and follow-up questions to offer as buttons.
 */
final class Reply
{
    /**
     * @param  list<array{label: string, url: string, note?: string|null}>  $links
     * @param  list<string>  $suggestions
     */
    public function __construct(
        public readonly string $text,
        public readonly array $links = [],
        public readonly array $suggestions = [],
        public readonly bool $ai = false,
    ) {}
}
