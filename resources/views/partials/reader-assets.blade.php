{{-- The reader's scripts (PDF.js and its worker, about 2 MB): fetched ahead
     on a book's page ($rel = prefetch, while the student decides) and from
     the start on the reading page ($rel = preload), instead of one after
     another once the page's own script runs. --}}
@php($module = $rel === 'prefetch' ? 'prefetch' : 'modulepreload')
<link rel="{{ $module }}" href="{{ Vite::asset('resources/js/reader.js') }}" @if ($module === 'prefetch') as="script" @endif>
<link rel="{{ $module }}" href="{{ Vite::asset('node_modules/pdfjs-dist/legacy/build/pdf.min.mjs') }}" @if ($module === 'prefetch') as="script" @endif>
<link rel="{{ $module }}" href="{{ Vite::asset('node_modules/pdfjs-dist/legacy/build/pdf.worker.min.mjs?url') }}" @if ($module === 'prefetch') as="script" @endif>
<link rel="prefetch" href="{{ Vite::asset('node_modules/pdfjs-dist/legacy/build/pdf.worker.min.mjs') }}" as="script">
