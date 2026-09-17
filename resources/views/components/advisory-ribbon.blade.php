@props(['advisory'])

{{--
    The single most urgent active advisory (picked in AppServiceProvider's
    partials.header composer), as a slim site-wide strip above the header --
    not the inline per-page cards this replaces. Full detail, every other
    active advisory, and per-listing/general grouping all live on the
    /advisories hub page; this only ever needs to say enough to make a
    traveler click through.
--}}

@php
    $icon = $advisory->severity === 'info' ? 'megaphone' : 'alert-triangle';
@endphp

<div class="advisory-ribbon advisory-ribbon--{{ $advisory->severity }}" id="advisoryRibbon"
     data-advisory-id="{{ $advisory->id }}" data-advisory-updated="{{ $advisory->updated_at->timestamp }}">
    <div class="advisory-ribbon__inner">
        <x-icon name="{{ $icon }}" class="advisory-ribbon__icon" />
        <p class="advisory-ribbon__text">
            <strong>{{ $advisory->title }}</strong> &mdash; {{ $advisory->message }}
        </p>
        <a href="{{ route('advisories.index') }}" class="advisory-ribbon__link">
            View details <span aria-hidden="true">&rarr;</span>
        </a>
        <button type="button" class="advisory-ribbon__close" id="advisoryRibbonClose" aria-label="Dismiss this advisory">
            &times;
        </button>
    </div>
</div>

@once
    <script>
        (function () {
            var ribbon = document.getElementById('advisoryRibbon');
            if (!ribbon) return;

            var closeBtn = document.getElementById('advisoryRibbonClose');
            // Keyed to id + updated_at, not just id: an admin editing or
            // re-raising the same advisory should reach a traveler who
            // already dismissed the earlier version of it this session.
            var key = 'advisory-dismissed-' + ribbon.dataset.advisoryId + '-' + ribbon.dataset.advisoryUpdated;

            var dismissed = false;
            try { dismissed = sessionStorage.getItem(key) === '1'; } catch (e) {}

            if (dismissed) {
                ribbon.remove();
            } else {
                closeBtn.addEventListener('click', function () {
                    try { sessionStorage.setItem(key, '1'); } catch (e) {}
                    ribbon.remove();
                });
            }
        })();
    </script>
@endonce
