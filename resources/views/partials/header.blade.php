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
                    <a href="{{ route('account.itineraries') }}">Hi, {{ auth('tourist')->user()->alias }}</a>
                    <form method="POST" action="{{ route('account.logout') }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="header-account-chip__logout">Log out</button>
                    </form>
                </span>
            @else
                <a href="{{ route('account.login') }}" class="header-account-link">Log in</a>
            @endauth
            <a href="{{ route('saved.index') }}" class="btn btn-outline">Saved</a>
            <a href="{{ route('plan.edit') }}" class="btn btn-primary">Plan My Trip</a>

            <button type="button" class="nav-toggle" aria-label="Toggle menu" onclick="document.getElementById('mobileMenu').classList.toggle('open')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
        </div>
    </div>

    <div class="mobile-menu" id="mobileMenu">
        <a href="{{ route('destinations.index') }}">Destinations</a>
        <a href="{{ route('accommodations.index') }}">Accommodations</a>
        <a href="{{ route('restaurants.index') }}">Restaurants</a>
        <a href="{{ route('packages.index') }}">Packages</a>
        <a href="{{ route('souvenir-centers.index') }}">Souvenir Centers</a>
        <a href="{{ route('tour-operators.index') }}">Tour Operators</a>
        <a href="{{ route('portal.establishment.register') }}">List your establishment</a>
        @auth('tourist')
            <a href="{{ route('account.itineraries') }}">My Itineraries</a>
            <a href="{{ route('account.saved') }}">Saved Places (Account)</a>
            <form method="POST" action="{{ route('account.logout') }}">
                @csrf
                <button type="submit" class="btn btn-outline btn-block">Log Out</button>
            </form>
        @else
            <a href="{{ route('account.login') }}">Log In</a>
            <a href="{{ route('account.register') }}">Create Free Account</a>
        @endauth
        <a href="{{ route('saved.index') }}" class="btn btn-outline btn-block">Saved</a>
        <a href="{{ route('plan.edit') }}" class="btn btn-primary btn-block">Plan My Trip</a>
    </div>
</header>

@if (($generalAdvisories ?? collect())->isNotEmpty())
    <div class="container" style="padding-top:16px;">
        <x-advisory-banner :advisories="$generalAdvisories" />
    </div>
@endif
