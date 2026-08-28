<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Partner Dashboard — ExploreDVO')</title>
    @include('partials.head-assets')
</head>
<body class="portal">
    <header class="site-header">
        <div class="container bar">
            <a href="{{ route('establishment.overview') }}" class="brand">Explore<span class="dot">DVO</span> <span class="brand-badge" style="font-size:.7rem; font-weight:700; color:var(--muted); margin-left:6px;">PARTNER</span></a>
            <div class="header-actions">
                <button type="button" class="admin-menu-toggle" onclick="document.getElementById('estSidebar').classList.toggle('open')"><x-icon name="menu" /> <span class="menu-toggle-label">Menu</span></button>
                <div class="notif-bell-wrap">
                    <button type="button" class="notif-bell" onclick="document.getElementById('notifDropdown').classList.toggle('open')" aria-label="Notifications">
                        <x-icon name="bell" />
                        @if ($navUnreadCount > 0)
                            <span class="notif-badge">{{ $navUnreadCount > 9 ? '9+' : $navUnreadCount }}</span>
                        @endif
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-dropdown-head">Notifications</div>
                        @forelse ($navNotifications as $notification)
                            <div class="notif-item {{ $notification->is_read ? '' : 'unread' }}">
                                <div class="notif-item-title">{{ $notification->title }}</div>
                                <p class="notif-item-msg">{{ $notification->message }}</p>
                                <div class="notif-item-date">{{ $notification->created_at->diffForHumans() }}</div>
                            </div>
                        @empty
                            <p class="notif-empty">No notifications yet.</p>
                        @endforelse
                        <a href="{{ route('establishment.notifications') }}" class="notif-dropdown-footer">View All</a>
                    </div>
                </div>
                <span class="admin-username" style="font-size:.85rem; color:var(--muted); margin-right:6px;">{{ auth('establishment')->user()->business_name }}</span>
                <form method="POST" action="{{ route('portal.logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline logout-btn">Log out</button>
                </form>
            </div>
        </div>
    </header>

    {{--
        Portal-wide alert bar. Composed in AppServiceProvider from
        EstablishmentAccount::portalStatus(), the single source of truth the
        Overview cards also read -- so the bar can never contradict the status
        shown further down the page.
    --}}
    @if (($navStatus['actionRequired'] ?? false) && $navStatus['detail'])
        <div class="portal-alert {{ $navStatus['tone'] === 'warn' ? 'portal-alert--warn' : '' }}">
            <div class="container">
                <svg class="portal-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>
                </svg>
                <div class="portal-alert__text">
                    <strong>{{ $navStatus['label'] }}.</strong> {{ $navStatus['detail'] }}
                </div>
                <a href="{{ route('establishment.notifications') }}" class="btn">Renew Accreditation &rarr;</a>
            </div>
        </div>
    @endif

    <div class="admin-topbar">
        <div class="container">
            <div>
                <h1>@yield('page-title', 'Overview')</h1>
                <div class="sub">@yield('page-sub', 'Manage your ExploreDVO listing')</div>
            </div>
        </div>
    </div>

    <div class="admin-shell">
        <aside class="admin-sidebar" id="estSidebar">
            <div class="group-label">My Establishment</div>
            <a href="{{ route('establishment.overview') }}" class="{{ request()->routeIs('establishment.overview') ? 'active' : '' }}">Overview</a>
            <a href="{{ route('establishment.listing.edit') }}" class="{{ request()->routeIs('establishment.listing.*') ? 'active' : '' }}">My Listing</a>
            <a href="{{ route('establishment.photos') }}" class="{{ request()->routeIs('establishment.photos') ? 'active' : '' }}">Photos</a>
            <a href="{{ route('establishment.reviews') }}" class="{{ request()->routeIs('establishment.reviews') ? 'active' : '' }}">
                <span>Guest Reviews</span>
                @if ($navUnrepliedCount > 0)
                    <span class="nav-badge" title="{{ $navUnrepliedCount }} awaiting your reply">{{ $navUnrepliedCount }}</span>
                @endif
            </a>
            <a href="{{ route('establishment.notifications') }}" class="{{ request()->routeIs('establishment.notifications') ? 'active' : '' }}">
                <span>Notifications</span>
                @if ($navUnreadCount > 0)
                    <span class="nav-badge" title="{{ $navUnreadCount }} unread">{{ $navUnreadCount }}</span>
                @endif
            </a>
        </aside>

        <main class="admin-main">
            <div class="container" style="padding-inline:0; max-width:100%;">
                @if (session('status'))
                    <div class="alert alert-success">{{ session('status') }}</div>
                @endif

                @yield('content')
            </div>
        </main>
    </div>

    {{-- Shared behaviours (custom file-input status, etc.). Everything in
         app.js is guarded by element lookups, so the public-site carousel and
         lightbox code is inert here. --}}
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>

    <script>
        document.addEventListener('click', function (e) {
            var wrap = document.querySelector('.notif-bell-wrap');
            var dropdown = document.getElementById('notifDropdown');
            if (wrap && dropdown && !wrap.contains(e.target)) {
                dropdown.classList.remove('open');
            }
        });
    </script>
</body>
</html>
