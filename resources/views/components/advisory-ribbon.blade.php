@props(['advisories'])

{{--
    A slim site-wide strip above the header (the advisories are picked in
    AppServiceProvider's partials.header composer, most urgent first). One
    advisory shows at a time. When there are several, a "1 of 3" counter and
    previous/next arrows let a traveller step through them by hand. Nothing
    rotates on its own: an important warning must not change before it has
    been read. Full detail lives on the /advisories hub page.
--}}

@php
    $first = $advisories->first();
    $count = $advisories->count();
    $dismissKey = $advisories->pluck('id')->implode('-').'-'.$advisories->max(fn ($a) => $a->updated_at->timestamp);
@endphp

<div class="advisory-ribbon advisory-ribbon--{{ $first->severity }}" id="advisoryRibbon"
     data-advisory-key="{{ $dismissKey }}" role="region" aria-label="Active advisories">
    <div class="advisory-ribbon__inner">
        <div class="advisory-ribbon__items" aria-live="polite">
            @foreach ($advisories as $advisory)
                <div class="advisory-ribbon__item" data-severity="{{ $advisory->severity }}" @if (! $loop->first) hidden @endif>
                    <x-icon name="{{ $advisory->severity === 'info' ? 'megaphone' : 'alert-triangle' }}" class="advisory-ribbon__icon" />
                    <p class="advisory-ribbon__text">
                        <strong>{{ $advisory->title }}</strong> &mdash; {{ $advisory->message }}
                    </p>
                    <a href="{{ route('advisories.index') }}" class="advisory-ribbon__link">
                        View details <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>
            @endforeach
        </div>

        @if ($count > 1)
            <div class="advisory-ribbon__pager" data-advisory-pager>
                <button type="button" class="advisory-ribbon__step" data-advisory-step="-1" aria-label="Previous advisory">&lsaquo;</button>
                <span><span data-advisory-position>1</span> of {{ $count }}</span>
                <button type="button" class="advisory-ribbon__step" data-advisory-step="1" aria-label="Next advisory">&rsaquo;</button>
            </div>
        @endif

        <button type="button" class="advisory-ribbon__close" id="advisoryRibbonClose" aria-label="Dismiss advisories">
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
            // Keyed to the ids + latest update, not just the ids: an admin editing or
            // re-raising an advisory should reach a traveller who dismissed the earlier version.
            var key = 'advisory-dismissed-' + ribbon.dataset.advisoryKey;

            var dismissed = false;
            try { dismissed = sessionStorage.getItem(key) === '1'; } catch (e) {}

            if (dismissed) {
                ribbon.remove();
                return;
            }

            closeBtn.addEventListener('click', function () {
                try { sessionStorage.setItem(key, '1'); } catch (e) {}
                ribbon.remove();
            });

            var items = ribbon.querySelectorAll('.advisory-ribbon__item');
            if (items.length < 2) return;

            var position = ribbon.querySelector('[data-advisory-position]');
            var current = 0;

            function show(index) {
                current = (index + items.length) % items.length;
                items.forEach(function (item, i) { item.hidden = i !== current; });
                ribbon.className = ribbon.className.replace(/advisory-ribbon--\w+/, 'advisory-ribbon--' + items[current].dataset.severity);
                position.textContent = String(current + 1);
            }

            ribbon.querySelectorAll('[data-advisory-step]').forEach(function (button) {
                button.addEventListener('click', function () { show(current + parseInt(button.dataset.advisoryStep, 10)); });
            });
        })();
    </script>
@endonce
