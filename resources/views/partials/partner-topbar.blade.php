{{--
    The partner portal's top bar: whether the listing is live, a menu to add something, what needs attention, and the
    account. The same menus as the DOT Admin's bar (public/js/admin-topbar.js drives both), without the search: a
    partner has one listing, so everything is a click away in the sidebar.
--}}
@php
    $partner = auth('establishment')->user();
    $partnerListing = $partner->matchedListing;
    $initials = collect(preg_split('/\s+/', trim((string) $partner->business_name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $publicUrl = \App\Support\PublicListingUrl::for($partner->listing_kind, $partnerListing);
    $visible = (bool) ($partnerListing && $partnerListing->is_accredited && ! $partnerListing->archived_at);
    $closedNotice = $partnerListing && method_exists($partnerListing, 'operatingNotice') ? $partnerListing->operatingNotice() : null;

    if (! $partnerListing) {
        $pill = ['tone' => 'warn', 'text' => $navStatus['label'] ?? 'Not linked yet'];
    } elseif (! $visible) {
        $pill = ['tone' => 'warn', 'text' => $partner->business_name.' is hidden from public search'];
    } elseif ($closedNotice) {
        $pill = ['tone' => 'warn', 'text' => $partner->business_name.' shows as closed'];
    } else {
        $pill = ['tone' => 'live', 'text' => $partner->business_name.' is live on the public site'];
    }

    $roleLabel = ucwords(str_replace('_', ' ', $partner->listing_kind));
    $attention = [];
    if ($navStatus && $navStatus['actionRequired'] && $navStatus['detail']) {
        $attention[] = ['tone' => 'warn', 'icon' => 'shield-check', 'title' => $navStatus['label'], 'detail' => $navStatus['detail'], 'action' => 'See', 'url' => route('establishment.overview')];
    }
    if ($navUnrepliedCount > 0) {
        $attention[] = ['tone' => 'info', 'icon' => 'chat', 'title' => $navUnrepliedCount.' '.\Illuminate\Support\Str::plural('review', $navUnrepliedCount).' '.($navUnrepliedCount === 1 ? 'is' : 'are').' waiting for your reply', 'detail' => 'Replying shows travellers you are listening.', 'action' => 'Reply', 'url' => route('establishment.reviews')];
    }
    if ($navPackagesChanges > 0) {
        $attention[] = ['tone' => 'warn', 'icon' => 'package', 'title' => 'DOT asked for a change to '.$navPackagesChanges.' '.\Illuminate\Support\Str::plural('package', $navPackagesChanges), 'detail' => 'Edit it and send it again.', 'action' => 'Open', 'url' => route('establishment.packages', ['status' => 'changes_requested'])];
    }
    foreach ($navNotifications->where('is_read', false)->take(3) as $note) {
        $attention[] = ['tone' => 'info', 'icon' => 'bell', 'title' => $note->title, 'detail' => \Illuminate\Support\Str::limit($note->message, 80), 'action' => 'Read', 'url' => route('establishment.notifications')];
    }
@endphp

<header class="adm-top" role="banner">
    <div class="adm-top__left">
        <button type="button" class="admin-menu-toggle" onclick="document.getElementById('estSidebar').classList.toggle('open')"><x-icon name="menu" /> <span class="menu-toggle-label">Menu</span></button>
        <a href="{{ route('establishment.overview') }}" class="adm-brand" aria-label="ExploreDVO Partner, overview">Explore<b>DVO</b> <span>PARTNER</span></a>
    </div>

    <div class="ptr-livewrap">
        <span class="ptr-live ptr-live--{{ $pill['tone'] }}"><i aria-hidden="true"></i>{{ $pill['text'] }}
            @if ($publicUrl && $visible)
                <span aria-hidden="true">&middot;</span> <a href="{{ $publicUrl }}" target="_blank" rel="noopener">View it<span class="sr-only"> (opens in a new tab)</span></a>
            @endif
        </span>
    </div>

    <div class="adm-top__right">
        @if ($partnerListing)
            <div class="adm-menu" data-menu>
                <button type="button" class="btn btn-primary adm-new" data-menu-btn aria-haspopup="menu" aria-expanded="false"><x-icon name="plus" /><span class="adm-hide-sm">Add</span><x-icon name="chevron-down" class="adm-caret" /></button>
                <div class="adm-pop adm-pop--new" role="menu" aria-label="Add something" hidden>
                    @if ($partner->listing_kind === 'tour_operator')
                        <a role="menuitem" href="{{ route('establishment.packages.create') }}" data-modal-form data-title="Add a package" data-sub="Save a draft any time. DOT reviews it before travellers can see it."><x-icon name="package" />A package</a>
                    @endif
                    <a role="menuitem" href="{{ route('establishment.promotions.create') }}" data-modal-form data-title="Add a promo" data-sub="Shown on your public page" data-size="narrow"><x-icon name="tag" />A promo or discount</a>
                    <a role="menuitem" href="{{ route('establishment.photos.create') }}" data-modal-form data-title="Upload photos" data-sub="JPG, PNG or WebP, up to 2 MB each" data-size="narrow"><x-icon name="camera" />Photos</a>
                </div>
            </div>
        @endif

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
                    <p class="adm-pop__empty"><x-icon name="check" /> Nothing needs attention. Every review has a reply and your accreditation is in order.</p>
                @endforelse
                <div class="adm-pop__foot"><a href="{{ route('establishment.notifications') }}">View all notifications</a></div>
            </div>
        </div>

        <div class="adm-menu" data-menu>
            <button type="button" class="adm-who" data-menu-btn aria-haspopup="menu" aria-expanded="false">
                <span class="adm-avatar" aria-hidden="true">{{ $initials }}</span>
                <span class="adm-who__t adm-hide-sm"><strong>{{ $partner->business_name }}</strong><small>{{ $roleLabel }}</small></span>
                <x-icon name="chevron-down" class="adm-caret adm-hide-sm" />
            </button>
            <div class="adm-pop adm-pop--who" role="menu" aria-label="Your account" hidden>
                <div class="adm-pop__me" role="presentation">
                    <span class="adm-avatar big" aria-hidden="true">{{ $initials }}</span>
                    <div><strong>{{ $partner->business_name }}</strong><small>{{ $partner->email }}</small><em>{{ $roleLabel }}</em></div>
                </div>
                @if ($publicUrl && $visible)
                    <a role="menuitem" href="{{ $publicUrl }}" target="_blank" rel="noopener"><x-icon name="globe" />View my public page<x-icon name="external-link" class="adm-ext" /><span class="sr-only"> (opens in a new tab)</span></a>
                @endif
                <a role="menuitem" href="{{ route('establishment.notifications') }}"><x-icon name="bell" />Notifications</a>
                <div class="adm-pop__sep" role="separator"></div>
                <form method="POST" action="{{ route('portal.logout') }}">
                    @csrf
                    <button type="submit" role="menuitem" class="is-danger"><x-icon name="log-out" />Log out</button>
                </form>
            </div>
        </div>
    </div>
</header>
