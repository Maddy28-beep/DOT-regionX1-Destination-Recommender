{{--
    The DOT Admin top bar: where you are working (the environment), a search for anything, a menu to add something new,
    what needs attention, and your account.

    Every menu is a button that opens a small panel (public/js/admin-topbar.js): arrows move through it, Esc closes it and
    hands focus back to the button, and one is open at a time. Add links open the add form in the modal where JavaScript
    allows and are plain links to the full-page form otherwise.
--}}
@php
    $admin = auth('admin')->user();
    $attention = app(\App\Services\Admin\AdminAttention::class)->items();
    $env = app()->environment();
    $isLive = $env === 'production';
    $envLabel = $isLive ? 'Live' : ucfirst($env);
    $initials = collect(preg_split('/\s+/', trim((string) ($admin->full_name ?: $admin->email))))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $role = ucfirst(str_replace('_', ' ', (string) $admin->role));
    $listingKinds = [
        'destinations' => ['Destination', 'map-pin'], 'accommodations' => ['Accommodation', 'building'], 'restaurants' => ['Restaurant', 'utensils'],
        'packages' => ['Package', 'package'], 'souvenir-centers' => ['Souvenir Center', 'bag'], 'tour-operators' => ['Tour Operator', 'van'],
    ];
@endphp

<header class="adm-top" role="banner">
    <div class="adm-top__left">
        <button type="button" class="admin-menu-toggle" onclick="document.getElementById('adminSidebar').classList.toggle('open')"><x-icon name="menu" /> <span class="menu-toggle-label">Menu</span></button>
        <a href="{{ route('admin.overview') }}" class="adm-brand" aria-label="ExploreDVO DOT Admin, overview">Explore<b>DVO</b> <span>DOT ADMIN</span></a>
        <span class="adm-env {{ $isLive ? 'is-live' : '' }}" title="{{ $isLive ? 'This is the live site. Changes here are seen by travellers.' : 'You are working on the '.strtolower($envLabel).' copy, not the live site.' }}"><i aria-hidden="true"></i>{{ $envLabel }}</span>
    </div>

    <div class="adm-search" id="admSearch" data-url="{{ route('admin.search') }}" role="search">
        <label for="admSearchInput" class="sr-only">Search listings, establishments, advisories, events and pages</label>
        <x-icon name="search" class="adm-search__ic" />
        <input id="admSearchInput" type="search" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="admSearchList" aria-autocomplete="list"
               placeholder="Search listings, establishments, advisories…" maxlength="80">
        <kbd class="adm-search__kbd" aria-hidden="true">Ctrl K</kbd>
        <div class="adm-pop adm-pop--search" id="admSearchList" role="listbox" aria-label="Search results" hidden></div>
    </div>

    <div class="adm-top__right">
        <div class="adm-menu" data-menu>
            <button type="button" class="btn btn-primary adm-new" data-menu-btn aria-haspopup="menu" aria-expanded="false"><x-icon name="plus" /><span class="adm-hide-sm">New</span><x-icon name="chevron-down" class="adm-caret" /></button>
            <div class="adm-pop adm-pop--new" role="menu" aria-label="Add something new" hidden>
                <div class="adm-pop__group" role="presentation">Listings</div>
                @foreach ($listingKinds as $type => [$label, $icon])
                    <a role="menuitem" href="{{ route('admin.listings.create', $type) }}" data-modal-form data-title="Add {{ $label }}"><x-icon :name="$icon" />{{ $label }}</a>
                @endforeach
                <div class="adm-pop__group" role="presentation">Content</div>
                <a role="menuitem" href="{{ route('admin.advisories.create') }}" data-modal-form data-title="Post advisory"><x-icon name="alert-triangle" />Advisory</a>
                <a role="menuitem" href="{{ route('admin.events.create') }}" data-modal-form data-title="Add event"><x-icon name="calendar" />Event</a>
                <a role="menuitem" href="{{ route('admin.postcard-slides.create') }}" data-modal-form data-title="Add slide"><x-icon name="camera" />Homepage slide</a>
            </div>
        </div>

        <div class="adm-menu" data-menu>
            <button type="button" class="adm-icon-btn adm-bell" data-menu-btn aria-haspopup="dialog" aria-expanded="false"
                    aria-label="{{ $attention === [] ? 'Nothing needs attention' : 'Needs attention: '.count($attention).' '.\Illuminate\Support\Str::plural('thing', count($attention)) }}">
                <x-icon name="bell" />
                @if ($attention !== [])<b class="adm-badge" aria-hidden="true">{{ count($attention) }}</b>@endif
            </button>
            <div class="adm-pop adm-pop--bell" role="dialog" aria-label="Needs attention" hidden>
                <div class="adm-pop__head"><h2>Needs attention</h2><span>{{ $attention === [] ? 'All clear' : count($attention).' '.\Illuminate\Support\Str::plural('thing', count($attention)) }}</span></div>
                @forelse ($attention as $item)
                    <a class="adm-att" href="{{ $item['url'] }}">
                        <span class="adm-att__ic {{ $item['tone'] }}"><x-icon :name="$item['icon']" /></span>
                        <span class="adm-att__t"><strong>{{ $item['title'] }}</strong>@if ($item['detail'] !== '')<small>{{ $item['detail'] }}</small>@endif</span>
                        <span class="adm-att__go">{{ $item['action'] }}</span>
                    </a>
                @empty
                    <p class="adm-pop__empty"><x-icon name="check" /> Nothing needs attention. Accreditations are current, no requests are waiting, and every destination has a map position.</p>
                @endforelse
            </div>
        </div>

        <div class="adm-menu" data-menu>
            <button type="button" class="adm-who" data-menu-btn aria-haspopup="menu" aria-expanded="false">
                <span class="adm-avatar" aria-hidden="true">{{ $initials }}</span>
                <span class="adm-who__t adm-hide-sm"><strong>{{ $admin->full_name ?: $admin->email }}</strong><small>{{ $role }}</small></span>
                <x-icon name="chevron-down" class="adm-caret adm-hide-sm" />
            </button>
            <div class="adm-pop adm-pop--who" role="menu" aria-label="Your account" hidden>
                <div class="adm-pop__me" role="presentation">
                    <span class="adm-avatar big" aria-hidden="true">{{ $initials }}</span>
                    <div><strong>{{ $admin->full_name ?: $admin->email }}</strong><small>{{ $admin->email }}</small><em>{{ $role }}</em></div>
                </div>
                <a role="menuitem" href="{{ url('/') }}" target="_blank" rel="noopener"><x-icon name="globe" />View the public site<x-icon name="external-link" class="adm-ext" /><span class="sr-only"> (opens in a new tab)</span></a>
                <a role="menuitem" href="{{ route('admin.audit-log') }}"><x-icon name="shield-check" />Audit log</a>
                <div class="adm-pop__sep" role="separator"></div>
                <form method="POST" action="{{ route('portal.logout') }}">
                    @csrf
                    <button type="submit" role="menuitem" class="is-danger"><x-icon name="log-out" />Log out</button>
                </form>
            </div>
        </div>
    </div>
</header>
