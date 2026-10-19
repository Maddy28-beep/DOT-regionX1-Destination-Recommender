{{--
    The site's main links, shared by the top bar and the phone drawer. Pass 'icons' => true (the drawer does) to put an
    icon in front of each one; the top bar does not, and renders exactly as before. (Each link's content stays on the
    same line as its opening tag, so no stray space appears inside it.)
--}}
@php($icons = $icons ?? false)
@foreach ([
    'destinations' => ['Destinations', 'map-pin'],
    'accommodations' => ['Stays', 'building'],
    'restaurants' => ['Dining', 'utensils'],
    'packages' => ['Packages', 'ticket'],
] as $routePrefix => [$label, $icon])
    <a href="{{ route($routePrefix.'.index') }}" @if(request()->routeIs($routePrefix.'.*')) aria-current="page" @endif>@if ($icons)<span class="mobile-menu__icon" aria-hidden="true"><x-icon :name="$icon" /></span>@endif{{ $label }}</a>
@endforeach
<div class="nav-more" data-nav-more>
    <button type="button" class="nav-more__toggle {{ request()->routeIs('souvenir-centers.*', 'tour-operators.*', 'events.*') ? 'is-active' : '' }}" aria-expanded="false" aria-controls="{{ $navId }}-more-links">
        @if ($icons)
            <span class="mobile-menu__label"><span class="mobile-menu__icon" aria-hidden="true"><x-icon name="grid" /></span>More</span>
        @else
            More
        @endif
        <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg>
    </button>
    <div class="nav-more__links" id="{{ $navId }}-more-links" hidden>
        <a href="{{ route('souvenir-centers.index') }}" @if(request()->routeIs('souvenir-centers.*')) aria-current="page" @endif>@if ($icons)<span class="mobile-menu__icon" aria-hidden="true"><x-icon name="tag" /></span>@endif Souvenir Centers</a>
        <a href="{{ route('tour-operators.index') }}" @if(request()->routeIs('tour-operators.*')) aria-current="page" @endif>@if ($icons)<span class="mobile-menu__icon" aria-hidden="true"><x-icon name="compass" /></span>@endif Tour Operators</a>
        <a href="{{ route('events.index') }}" @if(request()->routeIs('events.*')) aria-current="page" @endif>@if ($icons)<span class="mobile-menu__icon" aria-hidden="true"><x-icon name="calendar" /></span>@endif Events</a>
    </div>
</div>
<a href="{{ route('advisories.index') }}" class="main-nav__advisories" @if(request()->routeIs('advisories.*')) aria-current="page" @endif>
    @if ($icons)<span class="mobile-menu__icon" aria-hidden="true"><x-icon name="alert-triangle" /></span>@endif
    <span class="main-nav__label">Advisories</span>
    @if (($activeAdvisoryCount ?? 0) > 0)
        <span class="main-nav__dot main-nav__dot--count" aria-hidden="true">{{ $activeAdvisoryCount > 9 ? '9+' : $activeAdvisoryCount }}</span>
        <span class="sr-only">({{ $activeAdvisoryCount }} active {{ \Illuminate\Support\Str::plural('advisory', $activeAdvisoryCount) }})</span>
    @endif
</a>
