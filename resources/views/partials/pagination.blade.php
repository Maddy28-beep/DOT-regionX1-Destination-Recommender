{{--
    Page links for a listing: previous, a short window of numbers, next.

    Showing every page number ran to a screen-wide row of boxes once a catalogue passed a dozen pages. This keeps
    the first page, the last page and the pages either side of the current one, with an ellipsis for the gap, and on
    a phone swaps the numbers for "Page 3 of 26" between two large arrows.

    $paginator   the LengthAwarePaginator (query string already carried by its links)
    $panel       optional data-view-panel value, for pages whose Grid/Map switch hides this along with the grid
--}}
@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();

    $pages = collect([1, $last])
        ->merge(range(max(1, $current - 1), min($last, $current + 1)))
        ->unique()->sort()->values();

    // A gap of exactly one page is shown as that page: an ellipsis that hides a single number saves nothing.
    $pages = $pages->flatMap(function ($page, $i) use ($pages) {
        $before = $i > 0 ? $pages[$i - 1] : null;

        return $before !== null && $page - $before === 2 ? [$before + 1, $page] : [$page];
    })->values();
@endphp

@if ($last > 1)
    <nav class="pagination pagination--windowed" aria-label="Pagination" @isset($panel) data-view-panel="{{ $panel }}" @endisset>
        @if ($paginator->onFirstPage())
            <span class="pagination__step is-disabled" aria-hidden="true">&lsaquo;</span>
        @else
            <a class="pagination__step" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page">&lsaquo;</a>
        @endif

        <span class="pagination__numbers">
            @php $before = null; @endphp
            @foreach ($pages as $page)
                @if ($before !== null && $page - $before > 1)
                    <span class="pagination__gap" aria-hidden="true">&hellip;</span>
                @endif
                @if ($page === $current)
                    <span class="pagination__page is-current" aria-current="page"><span class="sr-only">Page </span>{{ $page }}</span>
                @else
                    <a class="pagination__page" href="{{ $paginator->url($page) }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                @endif
                @php $before = $page; @endphp
            @endforeach
        </span>

        <span class="pagination__status" aria-hidden="true">Page <strong>{{ $current }}</strong> of {{ $last }}</span>

        @if ($paginator->hasMorePages())
            <a class="pagination__step" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page">&rsaquo;</a>
        @else
            <span class="pagination__step is-disabled" aria-hidden="true">&rsaquo;</span>
        @endif
    </nav>
@endif
