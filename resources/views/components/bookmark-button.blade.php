{{--
    Save / unsave a book. A plain form post without JavaScript; with it,
    resources/js/bookmarks.js toggles in place.
--}}
@props(['book', 'saved' => false, 'compact' => false])

<form method="POST" action="{{ route($saved ? 'bookmarks.destroy' : 'bookmarks.store', $book) }}"
    data-bookmark
    data-store-url="{{ route('bookmarks.store', $book) }}"
    data-destroy-url="{{ route('bookmarks.destroy', $book) }}"
    {{ $attributes }}>
    @csrf
    <input type="hidden" name="_method" value="{{ $saved ? 'DELETE' : 'POST' }}">
    <button type="submit"
        @class(['bookmark-btn', 'bookmark-btn-compact' => $compact, 'btn btn-secondary' => ! $compact])
        aria-pressed="{{ $saved ? 'true' : 'false' }}"
        aria-label="{{ $saved ? 'Remove from saved books' : 'Save' }}: {{ $book->title }}"
        data-label-on="Remove from saved books: {{ $book->title }}"
        data-label-off="Save: {{ $book->title }}">
        <x-icon name="bookmark" class="bookmark-icon-off" />
        <x-icon name="bookmark-check" class="bookmark-icon-on" />
        @unless ($compact)
            <span class="bookmark-text-off">Save</span>
            <span class="bookmark-text-on">Saved</span>
        @endunless
    </button>
</form>
