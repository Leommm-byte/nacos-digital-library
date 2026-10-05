{{--
    A book's cover, lazy-loaded through BookCoverController, or a generated
    placeholder: a quiet tinted cover with the title's initials.
--}}
@props(['book', 'eager' => false])

@if ($url = $book->coverUrl())
    <img src="{{ $url }}" alt="" width="300" height="400" decoding="async"
        @unless ($eager) loading="lazy" @endunless
        {{ $attributes->class(['book-cover']) }}>
@else
    @php
        $words = collect(preg_split('/\s+/u', trim($book->title)) ?: [])
            ->filter(fn ($word) => preg_match('/^\p{L}/u', $word))
            ->take(2);
        $initials = mb_strtoupper($words->map(fn ($word) => mb_substr($word, 0, 1))->implode('')) ?: 'B';
        $tone = ['green', 'yellow', 'blue', 'violet'][$book->id % 4];
    @endphp
    <div {{ $attributes->class(['book-cover', 'book-cover-placeholder', 'cover-'.$tone]) }} aria-hidden="true">
        <span class="book-cover-initials">{{ $initials }}</span>
        <span class="book-cover-mark">NACOS · {{ $book->level->label() }}</span>
    </div>
@endif
