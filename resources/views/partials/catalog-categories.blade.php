@php
    $categoryParam = $categoryParam ?? 'type';
    $allCategoriesLabel = $allCategoriesLabel ?? 'All Types';
    $moreCategoriesLabel = $moreCategoriesLabel ?? 'More categories';
@endphp
<nav class="catalog-categories" aria-label="{{ $categoryLabel }}">
    <a href="{{ request()->fullUrlWithQuery([$categoryParam => null, 'page' => null]) }}" class="chip {{ request($categoryParam) ? '' : 'active' }}" @if (!request($categoryParam)) aria-current="true" @endif>{{ $allCategoriesLabel }}</a>
    @foreach ($featuredTypes as $type => $label)
        <a href="{{ request()->fullUrlWithQuery([$categoryParam => $type, 'page' => null]) }}" class="chip {{ request($categoryParam) === $type ? 'active' : '' }}" @if (request($categoryParam) === $type) aria-current="true" @endif>{{ $label }}</a>
    @endforeach
    {{-- On a phone the chips are one scrolling row, where the "More" dropdown below would be clipped, so the rest of
         the categories are chips of their own there (and hidden on a wide screen, which has the dropdown). --}}
    @foreach ($moreTypes as $type)
        <a href="{{ request()->fullUrlWithQuery([$categoryParam => $type, 'page' => null]) }}" class="chip catalog-categories__extra {{ request($categoryParam) === $type ? 'active' : '' }}" @if (request($categoryParam) === $type) aria-current="true" @endif>{{ $type }}</a>
    @endforeach
    @if ($moreTypes->isNotEmpty())
        <details class="catalog-categories__more">
            <summary class="chip {{ $moreTypeSelected ? 'active' : '' }}">
                {{ $moreTypeSelected ? request($categoryParam) : $moreCategoriesLabel }}
                <svg viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg>
            </summary>
            <div class="catalog-categories__menu">
                @foreach ($moreTypes as $type)
                    <a href="{{ request()->fullUrlWithQuery([$categoryParam => $type, 'page' => null]) }}" @if (request($categoryParam) === $type) aria-current="true" @endif>{{ $type }}</a>
                @endforeach
            </div>
        </details>
    @endif
</nav>
