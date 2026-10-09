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
        <div class="survey-invite__intro">
            <img class="survey-invite__davo" src="{{ asset('images/davo-survey-v2.webp') }}" alt="" width="72" height="86">
            <div>
                <span class="survey-invite__kicker">A moment with Davo</span>
                <h2 id="surveyInviteTitle">How was your Davao trip?</h2>
            </div>
        </div>
        <p id="surveyInviteDescription">Finished your visit? Tell us what worked well and what could be better for your next Davao adventure.</p>
        <div class="survey-invite__note"><x-icon name="shield-check" /><span>Optional feedback · No sign-up needed</span></div>
        <div class="survey-invite__actions">
            <a href="{{ route('exit-survey.create') }}" class="btn btn-primary" data-survey-answer>Share my experience <span aria-hidden="true">&rarr;</span></a>
            <button type="button" class="btn btn-outline" data-survey-dismiss>Maybe later</button>
        </div>
    </aside>
    <div id="surveyInviteAnnouncement" class="sr-only" aria-live="polite"></div>
@endif
<script src="{{ asset('js/survey-invite.js') }}?v={{ filemtime(public_path('js/survey-invite.js')) }}" defer></script>
