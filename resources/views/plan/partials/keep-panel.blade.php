{{-- Private-link behavior stays in ItineraryKeep; this partial only presents its existing states. --}}
@php $days = (int) config('privacy.kept_retention_days', 90); @endphp
<link rel="stylesheet" href="{{ asset('css/keep-plan.css') }}?v={{ filemtime(public_path('css/keep-plan.css')) }}">
<section class="keep-plan {{ ! $itinerary->edit_token_hash ? 'keep-plan--confirm' : '' }}" id="keep" aria-labelledby="keepTitle" @unless ($itinerary->edit_token_hash) data-keep-confirm @endunless>
    @if ($keep)
        <header class="keep-plan__heading">
            <span class="keep-plan__check" aria-hidden="true"><x-icon name="check" /></span>
            <div><h2 id="keepTitle">Your plan is kept</h2><p class="keep-plan__lead">Use your private link to open and edit this plan on any device.</p></div>
        </header>
        <div class="keep-plan__links">
            <div class="keep-plan__field">
                <label class="keep-plan__label" for="keepEdit">Your private link</label>
                <div><div class="keep-plan__row"><input id="keepEdit" type="text" readonly value="{{ $keep['edit'] }}" data-keep-input aria-describedby="keepEditHint"><button type="button" class="btn btn-outline" data-keep-copy="keepEdit" aria-label="Copy your private link">Copy</button></div><p id="keepEditHint" class="keep-plan__hint keep-plan__hint--private"><x-icon name="info" />Anyone with this link can change your plan. Keep it to yourself.</p></div>
            </div>
            <div class="keep-plan__field">
                <label class="keep-plan__label" for="keepShare">Share a view-only copy</label>
                <div><div class="keep-plan__row"><input id="keepShare" type="text" readonly value="{{ $keep['share'] }}" data-keep-input aria-describedby="keepShareHint"><button type="button" class="btn btn-outline" data-keep-copy="keepShare" aria-label="Copy the view-only link">Copy</button></div><p id="keepShareHint" class="keep-plan__hint">For your travel companions. They cannot edit the plan.</p></div>
            </div>
        </div>
        <details class="keep-plan__device" data-keep-device>
            <summary><x-icon name="qr" /><span>Open on another device</span><x-icon name="chevron-down" /></summary>
            <div class="keep-plan__device-body">
                <figure class="keep-plan__qr"><div role="img" aria-label="QR code for your private link">{!! $keep['qr'] !!}</div></figure>
                <div><h3>Scan to continue your trip</h3><p>Open your phone camera and scan this code to open and edit your plan.</p><p class="keep-plan__hint keep-plan__hint--private"><x-icon name="info" />This is your private link. Keep this QR code to yourself.</p><button type="button" class="keep-plan__linkbtn" data-keep-qr-close hidden>Hide QR code</button></div>
            </div>
        </details>
        <footer class="keep-plan__footer"><p><x-icon name="calendar" />Kept for {{ $days }} days after your last visit.</p><form method="POST" action="{{ route('plan.keep.renew') }}" onsubmit="return confirm('Make a new link? The old links will stop working.');">@csrf<button type="submit" class="keep-plan__linkbtn">Replace links</button></form></footer>
        <span class="sr-only" role="status" data-keep-copy-status></span>
    @elseif ($itinerary->edit_token_hash)
        <header class="keep-plan__heading"><span class="keep-plan__check" aria-hidden="true"><x-icon name="check" /></span><div><h2 id="keepTitle">Your plan is kept</h2><p class="keep-plan__lead">Your private link cannot be shown again in this browser. If you no longer have it, replace the links. The old links will stop working.</p></div></header>
        <footer class="keep-plan__footer"><p><x-icon name="calendar" />Kept for {{ $days }} days after your last visit.</p><form method="POST" action="{{ route('plan.keep.renew') }}" onsubmit="return confirm('Make a new link? The old links will stop working.');">@csrf<button type="submit" class="btn btn-outline">Replace links</button></form></footer>
    @else
        <button type="button" class="keep-plan__close" data-keep-dismiss aria-label="Close save confirmation" hidden><x-icon name="x" /></button>
        <span class="keep-plan__confirm-icon" aria-hidden="true"><x-icon name="heart" /></span>
        <h2 id="keepTitle">Open this trip again</h2>
        <p class="keep-plan__lead">Create a private link to continue on any device. No account needed.</p>
        <p class="keep-plan__retention">Deleted {{ $days }} days after your last visit. Lost links cannot be recovered.</p>
        <form method="POST" action="{{ route('plan.keep') }}" class="keep-plan__confirm-actions">@csrf<button type="button" class="btn btn-outline" data-keep-dismiss hidden>Not now</button><button type="submit" class="btn btn-primary">Create private link</button></form>
    @endif
</section>
<script src="{{ asset('js/keep-plan.js') }}?v={{ filemtime(public_path('js/keep-plan.js')) }}" defer></script>
