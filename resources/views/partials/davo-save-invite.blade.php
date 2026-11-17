<aside class="davo-invite" data-davo-invite data-invite-key="dvo-save-itinerary-{{ $itinerary->id }}" hidden aria-labelledby="davoSaveInviteTitle">
    <div class="davo-invite__top">
        <span class="davo-invite__eyebrow">Your travel buddy</span>
        <button type="button" class="davo-invite__close" data-davo-invite-close aria-label="Dismiss Davo’s save reminder">&times;</button>
    </div>
    <div class="davo-invite__intro">
        <img src="{{ asset('images/davo-adventure-ready.webp') }}" width="64" height="64" alt="" class="davo-invite__avatar">
        <div>
            <h2 id="davoSaveInviteTitle">Your adventure is ready.</h2>
            <p>Save your plan for later.</p>
        </div>
    </div>
    <button type="submit" form="itinerarySaveForm" class="btn btn-primary davo-invite__action" data-davo-invite-link><x-icon name="heart" /> Save itinerary</button>
</aside>
<link rel="stylesheet" href="{{ \App\Support\StyleAsset::url('css/davo-planner-invite.css') }}">
<script src="{{ asset('js/davo-planner-invite.js') }}?v={{ filemtime(public_path('js/davo-planner-invite.js')) }}" defer></script>
