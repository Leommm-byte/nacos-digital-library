{{--
    "Keep offline": saves the book's PDF on this phone so it opens with no
    data (resources/js/offline.js). Hidden until the script confirms the
    browser can do it.
--}}
@props(['book', 'file'])

@php
    $user = auth()->user();
    $mb = max(0.1, round($file->size_bytes / 1048576, 1));
@endphp

<div class="keep-offline" data-keep-offline hidden
    data-id="{{ $book->public_id }}"
    data-file="{{ route('books.file', ['book' => $book, 'v' => substr($file->sha256, 0, 12)]) }}"
    data-title="{{ $book->title }}"
    data-author="{{ $book->author }}"
    data-level="{{ $book->level->label() }}"
    data-size="{{ $file->size_bytes }}"
    data-watermark="{{ $user?->matric_number }}"
    data-owner="{{ $user ? \App\Support\Offline::owner($user) : '' }}">
    <x-button type="button" variant="secondary" icon="hard-drive-download" data-keep-offline-save>
        Keep offline <span class="font-normal text-muted">· {{ $mb }} MB</span>
    </x-button>
    <x-button type="button" variant="secondary" icon="hard-drive-download" data-keep-offline-progress disabled hidden>
        Saving… <span data-keep-offline-percent>0%</span>
    </x-button>
    <x-button type="button" variant="ghost" icon="circle-check" data-keep-offline-remove hidden
        aria-label="On this phone. Remove the offline copy">
        On this phone
    </x-button>
    <p class="keep-offline-error" data-keep-offline-error role="alert" hidden></p>
</div>
