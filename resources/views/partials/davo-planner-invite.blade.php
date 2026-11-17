<aside class="davo-invite" data-davo-invite hidden aria-labelledby="davoInviteTitle">
    <div class="davo-invite__top">
        <span class="davo-invite__eyebrow">Your travel buddy</span>
        <button type="button" class="davo-invite__close" data-davo-invite-close aria-label="Dismiss Davo’s planner invitation">&times;</button>
    </div>
    <div class="davo-invite__intro">
        <img src="{{ asset('images/davo-128.webp') }}" width="64" height="64" alt="" class="davo-invite__avatar">
        <div>
            <h2 id="davoInviteTitle">Not sure where to start?</h2>
            <p>Tell me what you like. Let’s plan your Davao trip.</p>
        </div>
    </div>
    <a class="btn btn-primary davo-invite__action" href="{{ url('/plan/start') }}" data-davo-invite-link>Open Trip Planner <x-icon name="arrow-right" /></a>
    <p class="davo-invite__note">No account needed</p>
</aside>
<link rel="stylesheet" href="{{ \App\Support\StyleAsset::url('css/davo-planner-invite.css') }}">
<script src="{{ asset('js/davo-planner-invite.js') }}?v={{ filemtime(public_path('js/davo-planner-invite.js')) }}" defer></script>
