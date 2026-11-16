@once
    <link rel="stylesheet" href="{{ asset('css/admin-written-feedback.css') }}?v={{ filemtime(public_path('css/admin-written-feedback.css')) }}">
@endonce
<section class="adm-card adm-written-feedback" id="{{ $feedbackId }}" aria-labelledby="{{ $feedbackId }}-title">
    <header class="adm-card__head">
        <div>
            <h2 id="{{ $feedbackId }}-title">{{ $feedbackHeading }}</h2>
            <p>{{ $feedbackComments->total() }} written {{ \Illuminate\Support\Str::plural('response', $feedbackComments->total()) }} &middot; Current filters apply &middot; Newest first</p>
        </div>
        <a class="btn btn-outline" href="{{ route($isSurveyFeedback ? 'admin.feedback-analysis' : 'admin.exit-surveys') }}">{{ $isSurveyFeedback ? 'Establishment reviews' : 'Exit survey comments' }}</a>
    </header>
    @forelse ($feedbackComments as $writtenFeedback)
        <article class="adm-written-feedback__entry">
            <div class="adm-written-feedback__meta">
                <strong>{{ $isSurveyFeedback ? 'Visitor feedback' : ($writtenFeedback->listing ? app(\App\Support\SampleData::class)->labelledName($writtenFeedback->listing) : 'Removed listing') }}</strong>
                <span>{{ ($isSurveyFeedback ? $writtenFeedback->submitted_at : $writtenFeedback->created_at)->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</span>
                <span>Rating: {{ $isSurveyFeedback ? $writtenFeedback->overall_rating : $writtenFeedback->rating }}/5</span>
                @if (!$isSurveyFeedback && $writtenFeedback->service_rating !== null)<span>Service: {{ $writtenFeedback->service_rating }}/5</span>@endif
                @if (!$isSurveyFeedback && $writtenFeedback->amenities_rating !== null)<span>Amenities: {{ $writtenFeedback->amenities_rating }}/5</span>@endif
                @if ($isSurveyFeedback && $writtenFeedback->data_source === 'demo')<span class="status-pill status-pending">Simulated response</span>@endif
            </div>
            <p class="adm-written-feedback__text">{{ $isSurveyFeedback ? $writtenFeedback->comments : $writtenFeedback->comment }}</p>
            @if (!$isSurveyFeedback && filled($writtenFeedback->owner_reply))
                <div class="adm-written-feedback__reply"><strong>Establishment reply</strong><p class="adm-written-feedback__text">{{ $writtenFeedback->owner_reply }}</p></div>
            @endif
        </article>
    @empty
        <p class="adm-written-feedback__empty">No written feedback in this selection. {{ $isSurveyFeedback ? 'Test-mode surveys are excluded; simulated comments appear only when their checkbox is selected.' : 'Try another date range or establishment.' }}</p>
    @endforelse
    @if ($feedbackComments->hasPages())<div class="adm-written-feedback__pages">{{ $feedbackComments->links() }}</div>@endif
</section>
