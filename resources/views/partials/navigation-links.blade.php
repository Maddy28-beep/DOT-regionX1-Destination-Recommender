@foreach (['destinations' => 'Destinations', 'accommodations' => 'Stays', 'restaurants' => 'Dining', 'packages' => 'Packages'] as $routePrefix => $label)
    <a href="{{ route($routePrefix.'.index') }}" @if(request()->routeIs($routePrefix.'.*')) aria-current="page" @endif>{{ $label }}</a>
@endforeach
<div class="nav-more" data-nav-more>
    <button type="button" class="nav-more__toggle {{ request()->routeIs('souvenir-centers.*', 'tour-operators.*') ? 'is-active' : '' }}" aria-expanded="false" aria-controls="{{ $navId }}-more-links">
        More <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg>
    </button>
    <div class="nav-more__links" id="{{ $navId }}-more-links" hidden>
        <a href="{{ route('souvenir-centers.index') }}" @if(request()->routeIs('souvenir-centers.*')) aria-current="page" @endif>Souvenir Centers</a>
        <a href="{{ route('tour-operators.index') }}" @if(request()->routeIs('tour-operators.*')) aria-current="page" @endif>Tour Operators</a>
    </div>
</div>
<a href="{{ route('advisories.index') }}" class="main-nav__advisories" @if(request()->routeIs('advisories.*')) aria-current="page" @endif>
    Advisories
    @if ($topAdvisory ?? null)
        <span class="main-nav__dot" aria-hidden="true"></span>
        <span class="sr-only">(active advisory)</span>
    @endif
</a>
