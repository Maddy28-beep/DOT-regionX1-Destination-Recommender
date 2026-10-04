@foreach (['destinations' => 'Destinations', 'accommodations' => 'Stays', 'restaurants' => 'Dining', 'packages' => 'Packages'] as $routePrefix => $label)
    <a href="{{ route($routePrefix.'.index') }}" @if(request()->routeIs($routePrefix.'.*')) aria-current="page" @endif>{{ $label }}</a>
@endforeach
<div class="nav-more" data-nav-more>
    <button type="button" class="nav-more__toggle {{ request()->routeIs('souvenir-centers.*', 'tour-operators.*', 'events.*') ? 'is-active' : '' }}" aria-expanded="false" aria-controls="{{ $navId }}-more-links">
        More <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg>
    </button>
    <div class="nav-more__links" id="{{ $navId }}-more-links" hidden>
        <a href="{{ route('souvenir-centers.index') }}" @if(request()->routeIs('souvenir-centers.*')) aria-current="page" @endif>Souvenir Centers</a>
        <a href="{{ route('tour-operators.index') }}" @if(request()->routeIs('tour-operators.*')) aria-current="page" @endif>Tour Operators</a>
        <a href="{{ route('events.index') }}" @if(request()->routeIs('events.*')) aria-current="page" @endif>Events</a>
    </div>
</div>
<a href="{{ route('advisories.index') }}" class="main-nav__advisories" @if(request()->routeIs('advisories.*')) aria-current="page" @endif>
    <span class="main-nav__label">Advisories</span>
    @if (($activeAdvisoryCount ?? 0) > 0)
        <span class="main-nav__dot main-nav__dot--count" aria-hidden="true">{{ $activeAdvisoryCount > 9 ? '9+' : $activeAdvisoryCount }}</span>
        <span class="sr-only">({{ $activeAdvisoryCount }} active {{ \Illuminate\Support\Str::plural('advisory', $activeAdvisoryCount) }})</span>
    @endif
</a>
