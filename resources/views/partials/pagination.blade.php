{{-- Compact pager: previous / page X of Y / next. Works well on phones. --}}
@if ($paginator->hasPages())
    <nav aria-label="Pages" class="mt-8 flex items-center justify-between gap-3">
        @if ($paginator->onFirstPage())
            <span class="btn btn-secondary btn-sm" aria-disabled="true"><x-icon name="chevron-left" /> Previous</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-secondary btn-sm"><x-icon name="chevron-left" /> Previous</a>
        @endif

        <p class="text-sm text-muted">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</p>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-secondary btn-sm">Next <x-icon name="chevron-right" /></a>
        @else
            <span class="btn btn-secondary btn-sm" aria-disabled="true">Next <x-icon name="chevron-right" /></span>
        @endif
    </nav>
@endif
