<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Partner Dashboard — ExploreDVO')</title>
    @include('partials.head-assets')
    {{-- The shell, top bar and modal are the DOT Admin's (one look for both consoles); partner-shell.css adds what only
         a partner has: the completeness ring, the preview, the photo grid, insights. --}}
    <link rel="stylesheet" href="{{ asset('css/admin-shell.css') }}?v={{ filemtime(public_path('css/admin-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/partner-shell.css') }}?v={{ filemtime(public_path('css/partner-shell.css')) }}">
    {{-- The map-position form opens in the modal, where a script tag arriving with the form would not run, so the map
         library is loaded with the page. --}}
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
</head>
<body class="admin partner">
    <a class="skip-link" href="#admin-main">Skip to main content</a>
    @include('partials.partner-topbar')

    <div class="admin-shell">
        <aside class="admin-sidebar" id="estSidebar" aria-label="Partner">
            <div class="group-label">My establishment</div>
            <a href="{{ route('establishment.overview') }}" class="{{ request()->routeIs('establishment.overview') ? 'active' : '' }}"><x-icon name="target" /><span>Overview</span></a>
            <a href="{{ route('establishment.listing.edit') }}" class="{{ request()->routeIs('establishment.listing.*') ? 'active' : '' }}"><x-icon name="building" /><span>My Listing</span></a>
            <a href="{{ route('establishment.photos') }}" class="{{ request()->routeIs('establishment.photos*') ? 'active' : '' }}"><x-icon name="camera" /><span>Photos</span></a>
            <a href="{{ route('establishment.promotions') }}" class="{{ request()->routeIs('establishment.promotions*') ? 'active' : '' }}"><x-icon name="tag" /><span>Promos &amp; Discounts</span></a>
            <a href="{{ route('establishment.reviews') }}" class="{{ request()->routeIs('establishment.reviews*') ? 'active' : '' }}"><x-icon name="chat" /><span>Guest Reviews</span>
                @if ($navUnrepliedCount > 0)<b class="ptr-nb" title="{{ $navUnrepliedCount }} awaiting your reply">{{ $navUnrepliedCount }}<span class="sr-only"> awaiting your reply</span></b>@endif
            </a>

            <div class="group-label">Performance</div>
            <a href="{{ route('establishment.insights') }}" class="{{ request()->routeIs('establishment.insights') ? 'active' : '' }}"><x-icon name="chart" /><span>Insights</span></a>

            <div class="group-label">Account</div>
            <a href="{{ route('establishment.notifications') }}" class="{{ request()->routeIs('establishment.notifications*') ? 'active' : '' }}"><x-icon name="bell" /><span>Notifications</span>
                @if ($navUnreadCount > 0)<b class="ptr-nb" title="{{ $navUnreadCount }} unread">{{ $navUnreadCount }}<span class="sr-only"> unread</span></b>@endif
            </a>
        </aside>

        <main class="admin-main" id="admin-main" tabindex="-1">
            <div class="admin-pagehead">
                <div>
                    <h1>@yield('page-title', 'Overview')</h1>
                    <div class="sub">@yield('page-sub', 'Manage your ExploreDVO listing')</div>
                </div>
                @hasSection('page-actions')
                    <div class="admin-pagehead__actions">@yield('page-actions')</div>
                @endif
            </div>

            <div class="container" style="padding-inline:0; max-width:100%;">
                @yield('content')
            </div>
        </main>
    </div>

    {{-- Every form of the portal opens here (public/js/admin-modal.js, shared with the DOT Admin): a native <dialog>.
         Each form is a real page too; the modal only changes how the link to it behaves. --}}
    <dialog id="adminModal" class="adm-modal" aria-labelledby="adminModalTitle">
        <header class="adm-modal__head">
            <div><h2 id="adminModalTitle"></h2><p id="adminModalSub"></p></div>
            <button type="button" class="adm-icon-btn" data-modal-close aria-label="Close"><x-icon name="x" /></button>
        </header>
        <div class="adm-modal__body" id="adminModalBody" aria-live="polite"></div>
    </dialog>
    <script src="{{ asset('js/admin-modal.js') }}?v={{ filemtime(public_path('js/admin-modal.js')) }}" defer></script>
    <script src="{{ asset('js/admin-topbar.js') }}?v={{ filemtime(public_path('js/admin-topbar.js')) }}" defer></script>
    <script src="{{ asset('js/partner-portal.js') }}?v={{ filemtime(public_path('js/partner-portal.js')) }}" defer></script>

    {{-- Shared behaviours (custom file-input status, etc.). Everything in app.js is guarded by element lookups. --}}
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
    @include('partials.flash-toast')
</body>
</html>
