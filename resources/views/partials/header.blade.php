{{-- The public topbar stays fixed; the spacer below reserves its measured height. --}}
<link rel="stylesheet" href="{{ \App\Support\StyleAsset::url('css/navigation-loading.css') }}">
{{-- A thin bar along the top starts at once; the pill below it only appears if the page is slow (public/js/navigation-loading.js). --}}
<div class="navigation-progress" data-navigation-progress hidden aria-hidden="true"><i></i></div>
<div class="navigation-loading" data-navigation-loading role="status" aria-live="polite" hidden>
    <span class="navigation-loading__spinner" aria-hidden="true"></span>
    <span class="navigation-loading__text">
        <span data-navigation-loading-label>Loading page…</span>
        <small data-navigation-loading-slow hidden>Taking a little longer…</small>
    </span>
</div>
<script src="{{ asset('js/navigation-loading.js') }}?v={{ filemtime(public_path('js/navigation-loading.js')) }}" defer></script>
<div class="site-topbar">
    @if (($ribbonAdvisories ?? collect())->isNotEmpty())
        <x-advisory-ribbon :advisories="$ribbonAdvisories" />
    @endif

    <header class="site-header">
        <div class="container bar">
            <span class="header-partners" role="group" aria-label="Tourism partners">
                <img src="{{ asset('images/tourism-logos/davao.webp') }}" alt="Davao" class="header-partners__davao" width="200" height="67" decoding="async">
                <img src="{{ asset('images/tourism-logos/dot.webp') }}" alt="Department of Tourism Philippines" class="header-partners__dot" width="100" height="99" decoding="async">
            </span>
            <a href="{{ route('home') }}" class="brand poster-title">
                <x-brand-mark class="brand-icon" />
                <span class="brand__name">Explore<span class="dot">DVO</span></span>
            </a>

            <nav class="main-nav" aria-label="Main navigation">
                @include('partials.navigation-links', ['navId' => 'desktop'])
            </nav>

            <div class="header-actions">
                {{-- Davao's weather today (left out when it cannot be read; shown on wide screens only). --}}
                @if (! empty($davaoWeather))
                    <span class="header-weather" title="{{ __('Davao today') }}: {{ $davaoWeather['words'] }}"><x-icon name="sun" aria-hidden="true" /> {{ $davaoWeather['temp'] }}&deg;C<span class="sr-only"> {{ $davaoWeather['words'] }}</span></span>
                @endif
                {{-- Icon-only so it costs 38px of the bar instead of a labelled button, with the number
                     of saved places on it. The label stays for screen readers and as a tooltip. --}}
                <a href="{{ route('saved.index') }}" class="header-saved" data-saved-link
                   aria-label="Saved places{{ ($savedCount ?? 0) > 0 ? ', '.$savedCount.' saved' : '' }}" title="Saved places"
                   @if(request()->routeIs('saved.*')) aria-current="page" @endif>
                    <x-icon name="heart" />
                    <span class="header-saved__count" data-count="{{ $savedCount ?? 0 }}" data-saved-count @if(($savedCount ?? 0) < 1) hidden @endif>{{ ($savedCount ?? 0) > 99 ? '99+' : ($savedCount ?? 0) }}</span>
                </a>
                <a href="{{ route('plan.choose') }}" @if(request()->routeIs('plan.*')) aria-current="page" @endif class="btn btn-primary header-plan">{{ __('Trip Planner') }}</a>

                <button type="button" class="nav-toggle" id="mobileMenuToggle" aria-label="Open menu" aria-haspopup="dialog" aria-expanded="false" aria-controls="mobileMenu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
            </div>
        </div>

    </header>
</div>
<div class="site-topbar-spacer" aria-hidden="true"></div>

{{--
    Premium slide-in drawer, not a dropped-down list of links. Deliberately a
    SIBLING of <header>, not nested inside it: .mobile-menu is position:fixed
    and needs the viewport as its containing block, but body.hero-page
    .site-header carries a backdrop-filter (for the translucent-blur header
    treatment) -- and a backdrop-filter/filter/transform on an ancestor
    establishes a new containing block for fixed descendants. Nested inside
    <header>, the drawer was sized and positioned relative to the 76px-tall
    header box instead of the full viewport (measured: height 76px instead of
    the full viewport height). Moving it outside fixes that at the source
    rather than reworking the header's blur.

    Grouped by intent (Explore / Plan) rather than a flat A-Z dump, with
    account-specific and partner-facing links (list-an-establishment, create
    an account, the account-scoped saved-places link) kept reachable in a
    de-emphasized utility area at the bottom rather than given equal visual
    weight to the six primary catalogue links -- nothing from the old flat
    list was dropped, it's just no longer presented as one undifferentiated
    column.
--}}
<div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>
<div class="mobile-menu" id="mobileMenu" role="dialog" aria-modal="true" aria-label="Site menu" inert>
    <div class="mobile-menu__head">
        <a href="{{ route('home') }}" class="brand poster-title">
            <x-brand-mark class="brand-icon" />
            <span class="brand__name">Explore<span class="dot">DVO</span></span>
        </a>
        <button type="button" class="mobile-menu__close" id="mobileMenuClose" aria-label="Close menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
    </div>

    <div class="mobile-menu__body">
        {{-- Sends the typed name to the same /search the homepage uses, which lands on the matching catalogue. --}}
        <form class="mobile-menu__search" action="{{ route('search') }}" method="GET" role="search">
            <x-icon name="search" aria-hidden="true" />
            <label for="mobileMenuSearch" class="sr-only">Search Davao Region</label>
            <input type="search" id="mobileMenuSearch" name="q" placeholder="{{ __('Search Davao Region') }}" maxlength="100" autocomplete="off" enterkeyhint="search">
        </form>

        <nav class="mobile-menu__group" aria-label="Mobile navigation">
            @include('partials.navigation-links', ['navId' => 'mobile', 'icons' => true])
        </nav>

        <div class="mobile-menu__pills">
            <a href="{{ route('saved.index') }}" class="mobile-menu__pill" data-saved-link
               aria-label="Saved places{{ ($savedCount ?? 0) > 0 ? ', '.$savedCount.' saved' : '' }}"
               @if(request()->routeIs('saved.*')) aria-current="page" @endif>
                <x-icon name="heart" />
                Saved
                <span class="mobile-menu__count" data-count="{{ $savedCount ?? 0 }}" data-saved-count aria-hidden="true" @if(($savedCount ?? 0) < 1) hidden @endif>{{ ($savedCount ?? 0) > 99 ? '99+' : ($savedCount ?? 0) }}</span>
            </a>
        </div>

        <p class="mobile-menu__label-small" id="mobileMenuAccount">Partners</p>
        <div class="mobile-menu__group mobile-menu__group--muted" role="group" aria-labelledby="mobileMenuAccount">
            <a href="{{ route('portal.establishment.register') }}" class="mobile-menu__utility"><span class="mobile-menu__icon" aria-hidden="true"><x-icon name="building" /></span>List your establishment</a>
        </div>

        <div class="mobile-menu__tip">
            <span class="mobile-menu__mascot" aria-hidden="true"><x-icon name="sparkles" /></span>
            <p>New here? Tell us what you like and we&rsquo;ll plan your Davao days for you.</p>
        </div>
    </div>

    <div class="mobile-menu__foot">
        <a href="{{ route('plan.choose') }}" @if(request()->routeIs('plan.*')) aria-current="page" @endif class="btn btn-primary btn-block"><x-icon name="map" /> Trip Planner</a>
    </div>
</div>
