<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'DOT Admin — ExploreDVO')</title>
    @include('partials.head-assets')
</head>
<body class="admin">
    <header class="site-header">
        <div class="container bar">
            <a href="{{ route('admin.overview') }}" class="brand">Explore<span class="dot">DVO</span> <span class="brand-badge" style="font-size:.7rem; font-weight:700; color:var(--muted); margin-left:6px;">DOT ADMIN</span></a>
            <div class="header-actions">
                <button type="button" class="admin-menu-toggle" onclick="document.getElementById('adminSidebar').classList.toggle('open')"><x-icon name="menu" /> <span class="menu-toggle-label">Menu</span></button>
                <span class="admin-username" style="font-size:.85rem; color:var(--muted); margin-right:6px;">{{ auth('admin')->user()->full_name }}</span>
                <form method="POST" action="{{ route('portal.logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline logout-btn">Log out</button>
                </form>
            </div>
        </div>
    </header>

    <div class="admin-topbar">
        <div class="container">
            <div>
                <h1>@yield('page-title', 'Overview')</h1>
                <div class="sub">@yield('page-sub', 'DOT Region XI tourism management console')</div>
            </div>
        </div>
    </div>

    <div class="admin-shell">
        <aside class="admin-sidebar" id="adminSidebar">
            {{-- One entry, not six: the in-page tab row already switches
                 between listing types, so duplicating that in the sidebar
                 was two controls for one job. --}}
            <div class="group-label">Tourism Information</div>
            <a href="{{ route('admin.listings.index', 'destinations') }}" class="{{ request()->routeIs('admin.listings.*') ? 'active' : '' }}">Manage Listings</a>

            <div class="group-label">Monitoring</div>
            <a href="{{ route('admin.overview') }}" class="{{ request()->routeIs('admin.overview') ? 'active' : '' }}">Overview</a>
            <a href="{{ route('admin.exit-surveys') }}" class="{{ request()->routeIs('admin.exit-surveys') ? 'active' : '' }}">Exit Survey Insights</a>
            <a href="{{ route('admin.association-rules') }}" class="{{ request()->routeIs('admin.association-rules') ? 'active' : '' }}">Association Rules</a>

            <div class="group-label">Accreditation</div>
            <a href="{{ route('admin.establishments') }}" class="{{ request()->routeIs('admin.establishments') ? 'active' : '' }}">Establishment Approvals</a>
            <a href="{{ route('admin.accreditation') }}" class="{{ request()->routeIs('admin.accreditation') ? 'active' : '' }}">Accreditation Monitoring</a>

            <div class="group-label">Insights</div>
            <a href="{{ route('admin.reports') }}" class="{{ request()->routeIs('admin.reports') ? 'active' : '' }}">Reports &amp; Export</a>
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

    {{-- Shared behaviours (bulk row selection). Everything in app.js is
         guarded by element lookups, so the public-site code is inert here. --}}
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</body>
</html>
