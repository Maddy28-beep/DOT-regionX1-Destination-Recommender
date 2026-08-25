<header class="site-header">
    <div class="container bar">
        <a href="{{ route('home') }}" class="brand">Explore<span class="dot">DVO</span></a>

        <nav class="main-nav">
            <a href="{{ route('destinations.index') }}">Destinations</a>
            <a href="{{ route('accommodations.index') }}">Accommodations</a>
            <a href="{{ route('restaurants.index') }}">Restaurants</a>
            <a href="{{ route('packages.index') }}">Packages</a>
            <a href="{{ route('souvenir-centers.index') }}">Souvenir Centers</a>
            <a href="{{ route('tour-operators.index') }}">Tour Operators</a>
            <a href="{{ route('portal.establishment.register') }}">List your establishment</a>
        </nav>

        <div class="header-actions">
            @auth('tourist')
                <a href="{{ route('tourist.dashboard') }}" class="btn btn-outline">My Trip</a>
            @else
                <a href="{{ route('tourist.login') }}" class="btn btn-outline">Sign in</a>
                <a href="{{ route('tourist.register') }}" class="btn btn-primary">Plan My Trip</a>
            @endauth

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
            <a href="{{ route('tourist.dashboard') }}" class="btn btn-primary btn-block">My Trip</a>
        @else
            <a href="{{ route('tourist.login') }}" class="btn btn-outline btn-block">Sign in</a>
            <a href="{{ route('tourist.register') }}" class="btn btn-primary btn-block">Plan My Trip</a>
        @endauth
    </div>
</header>
