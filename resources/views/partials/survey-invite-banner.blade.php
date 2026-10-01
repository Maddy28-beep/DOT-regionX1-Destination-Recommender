@php
    $surveyInviteEligible = request()->routeIs('home', 'destinations.*', 'accommodations.*', 'restaurants.*', 'packages.*', 'souvenir-centers.*', 'tour-operators.*', 'advisories.*');
    $surveyInvitePreview = app()->environment('local') && request()->boolean('preview_survey');
@endphp
<div id="surveyInviteState" hidden
     data-completed="{{ session('last_exit_survey_id') ? 'true' : 'false' }}"></div>
@if ($surveyInviteEligible)
    <aside id="surveyInvite" class="survey-invite" role="dialog" aria-modal="false"
           aria-labelledby="surveyInviteTitle" aria-describedby="surveyInviteDescription" hidden
           data-preview="{{ $surveyInvitePreview ? 'true' : 'false' }}"
           data-check-in="{{ session('show_survey_invite') ? 'true' : 'false' }}">
        <button type="button" class="survey-invite__close" data-survey-dismiss aria-label="Close survey invitation">&times;</button>
        <span class="survey-invite__kicker">Your Davao experience</span>
        <h2 id="surveyInviteTitle">Finished exploring Davao?</h2>
        <p id="surveyInviteDescription">Share your trip experience to help improve local tourism. Still exploring? Come back when your visit is complete.</p>
        <div class="survey-invite__actions">
            <a href="{{ route('exit-survey.create') }}" class="btn btn-primary" data-survey-answer>Answer survey <span aria-hidden="true">&rarr;</span></a>
            <button type="button" class="btn btn-outline" data-survey-dismiss>Not yet</button>
        </div>
    </aside>
    <div id="surveyInviteAnnouncement" class="sr-only" aria-live="polite"></div>
@endif
<script src="{{ asset('js/survey-invite.js') }}?v={{ filemtime(public_path('js/survey-invite.js')) }}" defer></script>
