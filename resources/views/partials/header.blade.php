{{-- The public topbar stays fixed; the spacer below reserves its measured height. --}}
<div class="site-topbar">
    @if (($ribbonAdvisories ?? collect())->isNotEmpty())
        <x-advisory-ribbon :advisories="$ribbonAdvisories" />
    @endif

    <header class="site-header">
        <div class="container bar">
            <a href="{{ route('home') }}" class="brand poster-title">
                <x-brand-mark class="brand-icon" />
                Explore<span class="dot">DVO</span>
            </a>

            <nav class="main-nav" aria-label="Main navigation">
                @include('partials.navigation-links', ['navId' => 'desktop'])
            </nav>

            <div class="header-actions">
                {{--
                    Exactly one extra element added here for the optional tourist
                    account, not a whole button row -- this bar already fought a
                    127px overflow from a single extra link (see the nav comment
                    above), so a second header-actions button would repeat that.
                --}}
                @auth('tourist')
                    {{-- One control instead of a name link plus a log-out icon the thumb could hit by
                         accident. Log out now sits at the bottom of the menu. The menu is hidden with
                         the rest of the bar below 1366px, where the drawer carries the same links. --}}
                    @php $alias = auth('tourist')->user()->alias; @endphp
                    <div class="account-menu" data-nav-more>
                        <button type="button" class="account-menu__toggle {{ request()->routeIs('account.itineraries*', 'account.saved') ? 'is-active' : '' }}" aria-expanded="false" aria-controls="accountMenuPanel" aria-label="Account menu for {{ $alias }}">
                            <span class="account-menu__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($alias, 0, 1)) }}</span>
                            <span class="account-menu__name">{{ $alias }}</span>
                            <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg>
                        </button>
                        <div class="account-menu__panel" id="accountMenuPanel" hidden>
                            <div class="account-menu__who">
                                <strong>{{ $alias }}</strong>
                                <span>Traveler account</span>
                            </div>
                            <a href="{{ route('account.itineraries') }}" @if(request()->routeIs('account.itineraries*')) aria-current="page" @endif>
                                <x-icon name="compass" />
                                My itineraries
                                <small>{{ $accountItineraryCount ?? 0 }}</small>
                            </a>
                            <a href="{{ route('account.saved') }}" @if(request()->routeIs('account.saved')) aria-current="page" @endif>
                                <x-icon name="heart" />
                                Saved places
                                <small>{{ $savedCount ?? 0 }}</small>
                            </a>
                            <form method="POST" action="{{ route('account.logout') }}">
                                @csrf
                                <button type="submit" class="account-menu__logout">
                                    <x-icon name="log-out" />
                                    Log out
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <a href="{{ route('account.login') }}" @if(request()->routeIs('account.login')) aria-current="page" @endif class="header-account-link">Log in</a>
                @endauth
                {{-- This used to always point at the anonymous, browser-only
                     list, so a signed-in traveler's own "Saved" button opened
                     someone else's list -- the session's, not their account's --
                     even while logged in. The mobile menu below already branched
                     on auth state; this one had not. --}}
                {{-- Icon-only so it costs 38px of the bar instead of a labelled button, with the number
                     of saved places on it. The label stays for screen readers and as a tooltip. --}}
                <a href="{{ auth('tourist')->check() ? route('account.saved') : route('saved.index') }}" class="header-saved" data-saved-link
                   aria-label="Saved places{{ ($savedCount ?? 0) > 0 ? ', '.$savedCount.' saved' : '' }}" title="Saved places"
                   @if(request()->routeIs('saved.*', 'account.saved')) aria-current="page" @endif>
                    <x-icon name="heart" />
                    <span class="header-saved__count" data-saved-count @if(($savedCount ?? 0) < 1) hidden @endif>{{ $savedCount ?? 0 }}</span>
                </a>
                <a href="{{ route('plan.choose') }}" @if(request()->routeIs('plan.*')) aria-current="page" @endif class="btn btn-primary header-plan">Plan My Trip <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>

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
@php($isTouristAuthed = auth('tourist')->check())
<div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>
<div class="mobile-menu" id="mobileMenu" role="dialog" aria-modal="true" aria-label="Site menu" inert>
    <div class="mobile-menu__head">
        <a href="{{ route('home') }}" class="brand poster-title">
            <x-brand-mark class="brand-icon" />
            Explore<span class="dot">DVO</span>
        </a>
        <button type="button" class="mobile-menu__close" id="mobileMenuClose" aria-label="Close menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
    </div>

    <nav class="mobile-menu__body" aria-label="Mobile navigation">
        <div class="mobile-menu__group">
            @include('partials.navigation-links', ['navId' => 'mobile'])
        </div>

        <div class="mobile-menu__divider"></div>

        <div class="mobile-menu__group mobile-menu__group--plain">
            @if ($isTouristAuthed)
                <a href="{{ route('account.itineraries') }}" @if(request()->routeIs('account.itineraries')) aria-current="page" @endif class="mobile-menu__utility">My Itineraries</a>
            @else
                <a href="{{ route('account.login') }}" @if(request()->routeIs('account.login')) aria-current="page" @endif class="mobile-menu__utility">Log in</a>
            @endif
            <a href="{{ $isTouristAuthed ? route('account.saved') : route('saved.index') }}" class="mobile-menu__utility mobile-menu__saved" @if(request()->routeIs('saved.*', 'account.saved')) aria-current="page" @endif>
                <x-icon name="heart" />
                Saved
            </a>

        </div>

        <div class="mobile-menu__divider"></div>

        <div class="mobile-menu__group mobile-menu__group--muted">
            @if ($isTouristAuthed)
                <span class="mobile-menu__account">Signed in as {{ auth('tourist')->user()->alias }}</span>
                <form method="POST" action="{{ route('account.logout') }}">
                    @csrf
                    <button type="submit" class="mobile-menu__utility mobile-menu__utility--btn">Log out</button>
                </form>
            @else
                <a href="{{ route('account.register') }}" class="mobile-menu__utility mobile-menu__utility--small">Create a free account</a>
            @endif
            <a href="{{ route('portal.establishment.register') }}" class="mobile-menu__utility mobile-menu__utility--small">List your establishment</a>
        </div>
    </nav>

    <div class="mobile-menu__foot">
        <a href="{{ route('plan.choose') }}" @if(request()->routeIs('plan.*')) aria-current="page" @endif class="btn btn-primary btn-block">Plan My Trip</a>
    </div>
</div>
