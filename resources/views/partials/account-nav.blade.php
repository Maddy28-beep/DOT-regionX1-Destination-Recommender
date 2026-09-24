{{--
    Sub-nav for the two tourist account pages (My Itineraries, Saved Places).
    Neither page linked to the other before this -- the only way between them
    was typing the URL. Reuses .chip-row--poster, the same pill-tab pattern
    the public catalog filters already use, rather than the admin console's
    sidebar treatment, since these are public-site pages, not an internal
    console.

    @auth-guarded even though every route that includes this already sits
    behind auth:tourist -- so this partial stays safe to include anywhere
    without needing to know the caller's guard state.
--}}
@auth('tourist')
    <div class="container" style="padding-top:20px;">
        <div class="chip-row chip-row--poster" style="margin-bottom:0;">
            <a href="{{ route('account.itineraries') }}" class="chip {{ request()->routeIs('account.itineraries*') ? 'active' : '' }}">My Itineraries</a>
            <a href="{{ route('account.saved') }}" class="chip {{ request()->routeIs('account.saved') ? 'active' : '' }}">Saved Places</a>
        </div>
    </div>
@endauth
