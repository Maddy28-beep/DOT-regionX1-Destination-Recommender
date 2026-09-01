<header class="site-header">
    <div class="container bar">
        <a href="{{ route('home') }}" class="brand poster-title">
            <svg class="brand-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle cx="12" cy="12" r="10" fill="currentColor"/>
                <circle cx="12" cy="12" r="4" fill="var(--stamp-red)"/>
            </svg>
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
                No Sign in button: travelers have no accounts. Saved places
                take its slot, since that is what people used to sign in for.
            --}}
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
        <a href="{{ config('dot.accreditation_portal') }}" target="_blank" rel="noopener noreferrer" class="ext-link">
            List your establishment
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                <path d="M15 3h6v6"/><path d="M10 14 21 3"/>
            </svg>
            <span class="sr-only">(opens the DOT accreditation portal in a new tab)</span>
        </a>
        <a href="{{ route('saved.index') }}" class="btn btn-outline btn-block">Saved</a>
        <a href="{{ route('plan.edit') }}" class="btn btn-primary btn-block">Plan My Trip</a>
    </div>
</header>
