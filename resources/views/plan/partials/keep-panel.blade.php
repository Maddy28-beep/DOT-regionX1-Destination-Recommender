{{--
    Keeping a plan without an account. A private link carries it on in any browser, a second link only shows it, and a new
    link replaces both. Expects $itinerary and $keep (the links, when this browser holds the secret).
--}}
@php $days = (int) config('privacy.kept_retention_days', 90); @endphp
<section class="keep-plan" id="keep" aria-labelledby="keepTitle">
    <h2 id="keepTitle">Keep this plan</h2>
    @if ($keep)
        <p class="keep-plan__lead">Your plan has a private link. Open it on any phone or computer to carry on. No account, no password.</p>
        <div class="keep-plan__grid">
            <div>
                <label class="keep-plan__label" for="keepEdit">Your link (open and change this plan)</label>
                <div class="keep-plan__row">
                    <input id="keepEdit" type="text" readonly value="{{ $keep['edit'] }}" data-keep-input>
                    <button type="button" class="btn btn-outline" data-keep-copy="keepEdit">Copy</button>
                </div>
                <label class="keep-plan__label" for="keepShare">Share link (only shows the plan)</label>
                <div class="keep-plan__row">
                    <input id="keepShare" type="text" readonly value="{{ $keep['share'] }}" data-keep-input>
                    <button type="button" class="btn btn-outline" data-keep-copy="keepShare">Copy</button>
                </div>
                <div class="keep-plan__actions">
                    <a class="btn btn-outline" href="{{ $keep['share'] }}?print=1" target="_blank" rel="noopener">Print or save as PDF</a>
                    <a class="btn btn-outline" href="{{ route('plan.itinerary.calendar') }}">Add to calendar</a>
                </div>
            </div>
            <figure class="keep-plan__qr">
                <div role="img" aria-label="QR code for your private link">{!! $keep['qr'] !!}</div>
                <figcaption>Scan with your phone to carry on there.</figcaption>
            </figure>
        </div>
        <p class="keep-plan__fine">Anyone who has your link can open this plan, so share the share link, not your own. Kept for {{ $days }} days after you last open it, then deleted. Sent the link to the wrong person?</p>
        <form method="POST" action="{{ route('plan.keep.renew') }}" class="keep-plan__inline" onsubmit="return confirm('Make a new link? The old links will stop working.');">@csrf <button type="submit" class="keep-plan__linkbtn">Make a new link</button></form>
    @elseif ($itinerary->edit_token_hash)
        <p class="keep-plan__lead">This plan is kept. Your private link was shown when it was made, and it is not stored where we can show it again. If you no longer have it, make a new one (the old link then stops working).</p>
        <form method="POST" action="{{ route('plan.keep.renew') }}">@csrf <button type="submit" class="btn btn-outline">Make a new link</button></form>
    @else
        <p class="keep-plan__lead">There are no accounts here. To keep this plan, get a private link: open it on any device to carry on, or share the view-only copy with whoever is travelling with you.</p>
        <form method="POST" action="{{ route('plan.keep') }}">@csrf <button type="submit" class="btn btn-primary"><x-icon name="heart" /> Get my private link</button></form>
        <p class="keep-plan__fine">Nothing about you is collected. The plan is kept for {{ $days }} days after you last open it, then deleted. If you lose the link, the plan cannot be recovered.</p>
    @endif
</section>
<style>
    .keep-plan { background: #fff; border: 1px solid #dfe8e2; border-radius: 16px; padding: 20px; margin: 18px 0; }
    .keep-plan h2 { margin: 0 0 6px; font-size: 1.2rem; }
    .keep-plan__lead { margin: 0 0 14px; color: #36503f; }
    .keep-plan__grid { display: grid; grid-template-columns: minmax(0, 1fr) 220px; gap: 20px; align-items: start; }
    .keep-plan__label { display: block; font-size: .85rem; font-weight: 600; margin: 10px 0 4px; }
    .keep-plan__row { display: flex; gap: 8px; }
    .keep-plan__row input { flex: 1; min-width: 0; font: inherit; font-size: .85rem; padding: 9px 10px; border: 1px solid #cfd9d2; border-radius: 10px; background: #f7faf8; }
    .keep-plan__actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
    .keep-plan__qr { margin: 0; text-align: center; }
    .keep-plan__qr svg { width: 100%; height: auto; max-width: 220px; }
    .keep-plan__qr figcaption { font-size: .8rem; color: #5b6f64; }
    .keep-plan__fine { margin: 14px 0 6px; font-size: .82rem; color: #5b6f64; }
    .keep-plan__inline { display: inline; }
    .keep-plan__linkbtn { background: none; border: 0; padding: 0; font: inherit; font-size: .85rem; color: #0b5a41; text-decoration: underline; cursor: pointer; }
    @media (max-width: 640px) { .keep-plan__grid { grid-template-columns: 1fr; } .keep-plan__qr { order: -1; } }
</style>
<script src="{{ asset('js/keep-plan.js') }}?v={{ filemtime(public_path('js/keep-plan.js')) }}" defer></script>
