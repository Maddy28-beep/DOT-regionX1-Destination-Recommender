{{--
    Shown once, right after a fresh QR check-in -- never a mandate. This is a
    one-request flash (session()->flash() in CheckInController), so it simply
    isn't here on the next page load whether the traveler took the survey or
    dismissed it. No dismissal tracking, no cookie, no repeat nagging.
--}}
@if (session('show_survey_invite'))
    <div class="container" style="padding-top:16px;">
        <div class="privacy-note" role="status" id="surveyInviteBanner">
            <x-icon name="shield-check" />
            <div style="flex:1;">
                <p style="margin:0 0 10px;">
                    <strong>How was your experience?</strong> Help improve tourism in Davao Region by
                    answering our short anonymous exit survey. Takes about 1&ndash;2 minutes.
                </p>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="{{ route('exit-survey.create') }}" class="btn btn-primary btn-xs">Take Exit Survey</a>
                    <button type="button" class="btn btn-outline btn-xs" onclick="document.getElementById('surveyInviteBanner').remove()">Maybe Later</button>
                </div>
            </div>
        </div>
    </div>
@endif
