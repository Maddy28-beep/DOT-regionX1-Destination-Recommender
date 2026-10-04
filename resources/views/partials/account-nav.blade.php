{{--
    Tabs for the two tourist account pages (My Itineraries, Saved Places), placed under each
    page's heading. Neither page linked to the other before this -- the only way between them
    was typing the URL. Underlined tabs with counts, not the pill chips the public catalog
    filters use, so they read as page navigation rather than another filter.

    @auth-guarded even though every route that includes this already sits behind auth:tourist,
    so the partial stays safe to include anywhere.
--}}
@auth('tourist')
    @php
        $account = auth('tourist')->user();
        $tabs = [
            ['route' => 'account.itineraries', 'label' => 'My itineraries', 'count' => $account->savedItineraries()->count(), 'active' => request()->routeIs('account.itineraries*')],
            ['route' => 'account.saved', 'label' => 'Saved places', 'count' => $account->savedDestinations()->count(), 'active' => request()->routeIs('account.saved')],
        ];
    @endphp
    <nav class="account-tabs" aria-label="Your account">
        <div class="container">
            @foreach ($tabs as $tab)
                <a href="{{ route($tab['route']) }}" class="account-tabs__tab {{ $tab['active'] ? 'is-active' : '' }}" @if($tab['active']) aria-current="page" @endif>
                    {{ $tab['label'] }} <span>{{ $tab['count'] }}</span>
                </a>
            @endforeach
        </div>
    </nav>
@endauth
