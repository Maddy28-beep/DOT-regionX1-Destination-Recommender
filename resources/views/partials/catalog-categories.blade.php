@php
    $categoryParam = $categoryParam ?? 'type';
    $allCategoriesLabel = $allCategoriesLabel ?? 'All Types';
    $moreCategoriesLabel = $moreCategoriesLabel ?? 'More categories';
    $fallbackIcon = $fallbackIcon ?? 'tag';
@endphp
<nav class="catalog-categories" aria-label="{{ $categoryLabel }}">
    <a href="{{ request()->fullUrlWithQuery([$categoryParam => null, 'page' => null]) }}" class="chip {{ request($categoryParam) ? '' : 'active' }}" @if (!request($categoryParam)) aria-current="true" @endif><x-icon :name="\App\Support\CategoryIcons::for($allCategoriesLabel, $fallbackIcon)" class="chip__icon" aria-hidden="true" />{{ $allCategoriesLabel }}</a>
    @foreach ($featuredTypes as $type => $label)
        <a href="{{ request()->fullUrlWithQuery([$categoryParam => $type, 'page' => null]) }}" class="chip {{ request($categoryParam) === $type ? 'active' : '' }}" @if (request($categoryParam) === $type) aria-current="true" @endif><x-icon :name="\App\Support\CategoryIcons::for($label, $fallbackIcon)" class="chip__icon" aria-hidden="true" />{{ $label }}</a>
    @endforeach
    @if (($dealsCount ?? 0) > 0 || request()->boolean('deals'))
        <a href="{{ request()->fullUrlWithQuery(['deals' => request()->boolean('deals') ? null : 1, 'page' => null]) }}" class="chip chip--deal {{ request()->boolean('deals') ? 'active' : '' }}" @if (request()->boolean('deals')) aria-current="true" @endif><x-icon name="ticket" class="chip__icon" aria-hidden="true" />Deals</a>
    @endif
    {{-- On a phone the chips are one scrolling row, where the "More" dropdown below would be clipped, so the rest of
         the categories are chips of their own there (and hidden on a wide screen, which has the dropdown). --}}
    @foreach ($moreTypes as $type)
        <a href="{{ request()->fullUrlWithQuery([$categoryParam => $type, 'page' => null]) }}" class="chip catalog-categories__extra {{ request($categoryParam) === $type ? 'active' : '' }}" @if (request($categoryParam) === $type) aria-current="true" @endif><x-icon :name="\App\Support\CategoryIcons::for($type, $fallbackIcon)" class="chip__icon" aria-hidden="true" />{{ $type }}</a>
    @endforeach
    @if ($moreTypes->isNotEmpty())
        <details class="catalog-categories__more" data-more-label="{{ $moreCategoriesLabel }}">
            <summary class="chip {{ $moreTypeSelected ? 'active' : '' }}">
                {{ $moreTypeSelected ? request($categoryParam) : $moreCategoriesLabel }}
                <svg viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg>
            </summary>
            <div class="catalog-categories__menu">
                @foreach ($moreTypes as $type)
                    <a href="{{ request()->fullUrlWithQuery([$categoryParam => $type, 'page' => null]) }}" @if (request($categoryParam) === $type) aria-current="true" @endif><x-icon :name="\App\Support\CategoryIcons::for($type, $fallbackIcon)" class="chip__icon" aria-hidden="true" />{{ $type }}</a>
                @endforeach
            </div>
        </details>
    @endif
</nav>
