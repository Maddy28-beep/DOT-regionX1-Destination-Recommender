<header class="site-header">
    <div class="container bar">
        <a href="{{ route('home') }}" class="brand poster-title">
            <x-brand-mark class="brand-icon" />
            Explore<span class="dot">DVO</span>
        </a>

        <nav class="main-nav">
            <a href="{{ route('destinations.index') }}">Destinations</a>
            <a href="{{ route('accommodations.index') }}">Accommodations</a>
            <a href="{{ route('restaurants.index') }}">Restaurants</a>
            <a href="{{ route('packages.index') }}">Packages</a>
            <a href="{{ route('souvenir-centers.index') }}">Souvenir Centers</a>
            <a href="{{ route('tour-operators.index') }}">Tour Operators</a>
            {{--
                "List your establishment" is deliberately not in this bar.
                .bar is a .container capped at 1200px (1160px inside padding),
                and with that link the nav measured 1274.9px -- 127px too wide
                at ANY viewport, which pushed the Sign in / Plan My Trip buttons
                past the container and gave the whole site a horizontal
                scrollbar on every screen narrower than ~1454px (so 1280, 1366
                and 1440 all showed it). Dropping it here recovers 180.7px.

                It is a partner-facing link on a tourist-facing bar, and it
                remains reachable from the footer and from the mobile menu
                below, so nothing is lost.
            --}}
        </nav>

        <div class="header-actions">
            {{--
                Exactly one extra element added here for the optional tourist
                account, not a whole button row -- this bar already fought a
                127px overflow from a single extra link (see the nav comment
                above), so a second header-actions button would repeat that.
            --}}
            @auth('tourist')
                <span class="header-account-chip">
                    <a href="{{ route('account.itineraries') }}" class="header-account-chip__name">
                        <x-icon name="user" />
                        <span>{{ auth('tourist')->user()->alias }}</span>
                    </a>
                    <form method="POST" action="{{ route('account.logout') }}" class="header-account-chip__logout-form">
                        @csrf
                        <button type="submit" class="header-account-chip__logout" aria-label="Log out" title="Log out">
                            <x-icon name="log-out" />
                        </button>
                    </form>
                </span>
            @else
                <a href="{{ route('account.login') }}" class="header-account-link">Log in</a>
            @endauth
            {{-- This used to always point at the anonymous, browser-only
                 list, so a signed-in traveler's own "Saved" button opened
                 someone else's list -- the session's, not their account's --
                 even while logged in. The mobile menu below already branched
                 on auth state; this one had not. --}}
            <a href="{{ auth('tourist')->check() ? route('account.saved') : route('saved.index') }}" class="btn btn-outline">
                <x-icon name="heart" />
                Saved
            </a>
            <a href="{{ route('plan.edit') }}" class="btn btn-primary">Plan My Trip</a>

            <button type="button" class="nav-toggle" id="mobileMenuToggle" aria-label="Open menu" aria-haspopup="dialog" aria-expanded="false" aria-controls="mobileMenu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
        </div>
    </div>

</header>

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

    <nav class="mobile-menu__body">
        <div class="mobile-menu__group">
            <div class="mobile-menu__label">Explore</div>
            <a href="{{ route('destinations.index') }}">Destinations</a>
            <a href="{{ route('accommodations.index') }}">Accommodations</a>
            <a href="{{ route('restaurants.index') }}">Restaurants</a>
        </div>

        <div class="mobile-menu__group">
            <div class="mobile-menu__label">Plan</div>
            <a href="{{ route('packages.index') }}">Packages</a>
            <a href="{{ route('tour-operators.index') }}">Tour Operators</a>
            <a href="{{ route('souvenir-centers.index') }}">Souvenir Centers</a>
        </div>

        <div class="mobile-menu__divider"></div>

        <div class="mobile-menu__group mobile-menu__group--plain">
            <a href="{{ $isTouristAuthed ? route('account.saved') : route('saved.index') }}" class="mobile-menu__utility">
                <x-icon name="heart" />
                Saved
            </a>
            @if ($isTouristAuthed)
                <a href="{{ route('account.itineraries') }}" class="mobile-menu__utility">My Itineraries</a>
            @else
                <a href="{{ route('account.login') }}" class="mobile-menu__utility">Log in</a>
            @endif
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
        <a href="{{ route('plan.edit') }}" class="btn btn-primary btn-block">Plan My Trip</a>
    </div>
</div>

@if (($generalAdvisories ?? collect())->isNotEmpty())
    <div class="container" style="padding-top:16px;">
        <x-advisory-banner :advisories="$generalAdvisories" />
    </div>
@endif
