{{--
    A book's cover, lazy-loaded through BookCoverController, or a generated
    placeholder (title on a tinted card) when there is none.
--}}
@props(['book', 'eager' => false])

@if ($url = $book->coverUrl())
    <img src="{{ $url }}" alt="" width="300" height="400" decoding="async"
        @unless ($eager) loading="lazy" @endunless
        {{ $attributes->class(['book-cover']) }}>
@else
    <div {{ $attributes->class(['book-cover', 'book-cover-placeholder', 'cover-tone-'.($book->id % 4)]) }} aria-hidden="true">
        <span class="book-cover-title">{{ \Illuminate\Support\Str::limit($book->title, 70) }}</span>
        <span class="book-cover-author">{{ $book->author }}</span>
    </div>
@endif
