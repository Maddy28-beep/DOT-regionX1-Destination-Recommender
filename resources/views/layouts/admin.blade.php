<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'DOT Admin — ExploreDVO')</title>
    @include('partials.head-assets')
    <link rel="stylesheet" href="{{ asset('css/admin-shell.css') }}?v={{ filemtime(public_path('css/admin-shell.css')) }}">
</head>
<body class="admin">
    <a class="skip-link" href="#admin-main">Skip to main content</a>
    @include('partials.admin-topbar')

    <div class="admin-shell">
        <aside class="admin-sidebar" id="adminSidebar">
            {{-- One entry, not six: the in-page tab row already switches
                 between listing types, so duplicating that in the sidebar
                 was two controls for one job. --}}
            <div class="group-label">Tourism Information</div>
            <a href="{{ route('admin.listings.index', 'destinations') }}" class="{{ request()->routeIs('admin.listings.*') ? 'active' : '' }}"><x-icon name="building" /><span>Manage Listings</span></a>

            <a href="{{ route('admin.advisories.index') }}" class="{{ request()->routeIs('admin.advisories.*') ? 'active' : '' }}"><x-icon name="alert-triangle" /><span>Advisories</span></a>

            <a href="{{ route('admin.events.index') }}" class="{{ request()->routeIs('admin.events.*') ? 'active' : '' }}"><x-icon name="calendar" /><span>Events</span></a>

            <a href="{{ route('admin.postcard-slides.index') }}" class="{{ request()->routeIs('admin.postcard-slides.*') ? 'active' : '' }}"><x-icon name="camera" /><span>Homepage carousel</span></a>

            <div class="group-label">Monitoring</div>
            <a href="{{ route('admin.overview') }}" class="{{ request()->routeIs('admin.overview') ? 'active' : '' }}"><x-icon name="target" /><span>Overview</span></a>
            <a href="{{ route('admin.exit-surveys') }}" class="{{ request()->routeIs('admin.exit-surveys') ? 'active' : '' }}"><x-icon name="chat" /><span>Exit Survey Insights</span></a>
            <a href="{{ route('admin.association-rules') }}" class="{{ request()->routeIs('admin.association-rules') ? 'active' : '' }}"><x-icon name="link" /><span>Association Rules</span></a>
            <a href="{{ route('admin.audit-log') }}" class="{{ request()->routeIs('admin.audit-log') ? 'active' : '' }}"><x-icon name="shield-check" /><span>Audit Log</span></a>
            <a href="{{ route('admin.data-completeness') }}" class="{{ request()->routeIs('admin.data-completeness*') ? 'active' : '' }}"><x-icon name="database" /><span>Data completeness</span></a>

            <div class="group-label">Accreditation</div>
            <a href="{{ route('admin.establishments') }}" class="{{ request()->routeIs('admin.establishments') ? 'active' : '' }}"><x-icon name="shield-check" /><span>Establishment Approvals</span></a>
            <a href="{{ route('admin.accreditation') }}" class="{{ request()->routeIs('admin.accreditation') ? 'active' : '' }}"><x-icon name="clock" /><span>Accreditation Monitoring</span></a>

            <div class="group-label">Insights</div>
            <a href="{{ route('admin.reports') }}" class="{{ request()->routeIs('admin.reports') ? 'active' : '' }}"><x-icon name="chart" /><span>Reports &amp; Export</span></a>
        </aside>

        <main class="admin-main" id="admin-main" tabindex="-1">
            {{-- The title sits in the content column, beside the sidebar, instead of in a bar across the whole page:
                 the page head is one row, so the work starts near the top of the screen. --}}
            <div class="admin-pagehead">
                <div>
                    <h1>@yield('page-title', 'Overview')</h1>
                    <div class="sub">@yield('page-sub', 'DOT Region XI tourism management console')</div>
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

    {{-- The add / edit forms open here (public/js/admin-modal.js): a native <dialog>, so Esc closes it and focus stays
         inside it. Each form is a real page too; this only changes how the link to it behaves. --}}
    <dialog id="adminModal" class="adm-modal" aria-labelledby="adminModalTitle">
        <header class="adm-modal__head">
            <div><h2 id="adminModalTitle"></h2><p id="adminModalSub"></p></div>
            <button type="button" class="adm-icon-btn" data-modal-close aria-label="Close"><x-icon name="x" /></button>
        </header>
        <div class="adm-modal__body" id="adminModalBody" aria-live="polite"></div>
    </dialog>
    <script src="{{ asset('js/admin-modal.js') }}?v={{ filemtime(public_path('js/admin-modal.js')) }}" defer></script>
    <script src="{{ asset('js/admin-topbar.js') }}?v={{ filemtime(public_path('js/admin-topbar.js')) }}" defer></script>

    {{-- Shared behaviours (bulk row selection). Everything in app.js is
         guarded by element lookups, so the public-site code is inert here. --}}
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
    @include('partials.flash-toast')
</body>
</html>
