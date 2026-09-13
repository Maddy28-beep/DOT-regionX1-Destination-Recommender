@props(['paginator'])

{{--
    Compact windowed pagination for the DOT Admin console -- first page, last
    page, and a small slider around the current page, with an ellipsis for
    any gap in between. Replaces the previous approach (every single page
    number from 1 to lastPage(), unwindowed), which was fine for a handful of
    pages but rendered dozens of buttons in a row on any table with real
    volume behind it (Accreditation Monitoring's ~40 pages, for one).

    Pure presentation: every method called here (currentPage, lastPage,
    previousPageUrl, nextPageUrl, url, onFirstPage, hasMorePages) is a
    standard LengthAwarePaginator method already used by the markup this
    replaces, so query-string preservation, filtering, and every other
    pagination behavior stay exactly as they were.
--}}

@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();

    // First page, last page, and one on each side of the current page --
    // deduplicated and sorted, so a small total (e.g. 3 pages) just shows
    // all three with no gap, while a large one (e.g. 40) collapses to
    // "1 ... 5 6 7 ... 40".
    $pages = collect([1, $last])
        ->merge(range(max(1, $current - 1), min($last, $current + 1)))
        ->unique()
        ->sort()
        ->values();
@endphp

@if ($last > 1)
    <div class="pagination">
        @if ($paginator->onFirstPage())
            <span class="disabled">&lsaquo; Previous</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}">&lsaquo; Previous</a>
        @endif

        @php $previousPage = null; @endphp
        @foreach ($pages as $page)
            @if ($previousPage !== null && $page - $previousPage > 1)
                <span class="pagination-ellipsis" aria-hidden="true">&hellip;</span>
            @endif
            <span class="{{ $page === $current ? 'active' : '' }}">
                <a href="{{ $paginator->url($page) }}" @if ($page === $current) aria-current="page" @endif>{{ $page }}</a>
            </span>
            @php $previousPage = $page; @endphp
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}">Next &rsaquo;</a>
        @else
            <span class="disabled">Next &rsaquo;</span>
        @endif
    </div>
@endif
