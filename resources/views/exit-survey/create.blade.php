@extends('layouts.app')

@section('title', 'Exit Survey — ExploreDVO')

@section('content')
<link rel="stylesheet" href="{{ asset('css/exit-survey.css') }}?v={{ filemtime(public_path('css/exit-survey.css')) }}">
<link rel="stylesheet" href="{{ asset('css/survey-stays.css') }}?v={{ filemtime(public_path('css/survey-stays.css')) }}">
<link rel="stylesheet" href="{{ asset('css/exit-survey-guided.css') }}?v={{ filemtime(public_path('css/exit-survey-guided.css')) }}">
<div class="exit-survey-page survey-guided" data-survey-wizard>
<div class="page-head">
    <div class="container survey-welcome">
        <div>
        <span class="poster-kicker" style="font-size:1.05rem;">how was your trip?</span>
        <h1 class="page-title" style="font-size:1.9rem; margin:0;">How was your Davao trip?</h1>
        <p>Visitor Exit Survey · Your feedback helps improve tourism in Davao.</p>
        </div>
    </div>
</div>

<div class="section-tight">
    <div class="container survey-container">

        @if ($errors->any())
            <div class="alert alert-error" role="alert">
                <ul style="margin:0; padding-left:18px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <nav class="survey-progress" aria-label="Survey steps" data-survey-progress hidden>
            @foreach (['Your trip', 'Your visit', 'Your experience'] as $stepLabel)
                <button type="button" data-survey-go="{{ $loop->index }}"><span>{{ $loop->iteration }}</span>{{ $stepLabel }}</button>
            @endforeach
        </nav>
        <div class="survey-layout">
        <aside class="survey-guide" aria-label="Davo’s survey guide">
            <video class="survey-guide__davo" data-davo-survey-video muted playsinline preload="auto" poster="{{ asset('images/davo-survey-video-poster.webp') }}?v={{ filemtime(public_path('images/davo-survey-video-poster.webp')) }}" width="568" height="746" aria-label="Davo, your animated survey guide">
                <source src="{{ asset('video/davo-survey-guide.mp4') }}?v={{ (is_file(public_path('video/davo-survey-guide.mp4')) ? filemtime(public_path('video/davo-survey-guide.mp4')) : 0) }}" type="video/mp4">
                <img src="{{ asset('images/davo-survey-video-poster.webp') }}" alt="Davo, your survey guide" width="160" height="190">
            </video>
            <div class="survey-guide__bubble" aria-live="polite" aria-atomic="true">
                <h2 data-survey-guide-title>How was your Davao trip?</h2>
                <p data-survey-guide-copy>Let’s start with a few trip details. You can skip anything on this step.</p>
            </div>
            <div class="survey-guide__privacy"><x-icon name="shield-check" /><div><p><strong>Anonymous</strong> · No name or email needed</p>
            <details><summary>Privacy details</summary><p>
                No name, email, or account details are collected. Responses aren’t linked to your profile and are
                used only for tourism analytics and service improvement under the Data Privacy Act (RA 10173).
            </p></details></div></div>
        </aside>

        @php
            $surveyErrorStep = $errors->hasAny(['origin', 'residency_type', 'travel_purpose', 'actual_days_stayed', 'estimated_total_spend']) ? 0
                : ($errors->hasAny(['places_visited', 'places_visited.*', 'activities', 'activities.*', 'stays', 'stays.*']) ? 1 : ($errors->any() ? 2 : 0));
        @endphp
        <div class="survey-form-stage" data-survey-stage>
        <form method="POST" action="{{ route('exit-survey.store') }}" data-survey-form data-initial-step="{{ $surveyErrorStep }}">
            @csrf
            {{-- Honeypot: kept off screen and out of the tab order. People never fill it; scripts that fill every field do. --}}
            <div class="hp-field" aria-hidden="true">
                <label for="{{ \App\Http\Controllers\ExitSurveyController::HONEYPOT_FIELD }}">Leave this field empty</label>
                <input type="text" id="{{ \App\Http\Controllers\ExitSurveyController::HONEYPOT_FIELD }}" name="{{ \App\Http\Controllers\ExitSurveyController::HONEYPOT_FIELD }}" value="" tabindex="-1" autocomplete="off">
            </div>
            @if ($isTest ?? false)
                <input type="hidden" name="test" value="1">
                <div class="alert alert-error" role="note" style="margin-bottom:18px;">
                    <strong>Test mode.</strong> Responses sent from this page are kept separate and are not counted in any
                    results or in the association rules.
                </div>
            @endif

            <div class="survey-panels" role="region" aria-label="Survey questions">
            <div class="panel" data-survey-step="0">
                <div class="panel-head">
                    <div>
                        <span class="survey-step">01 · Trip details</span>
                        <h2 tabindex="-1">Tell me about your visit <span class="survey-optional-badge">Optional</span></h2>
                        <p>These details are optional. Share what you’re comfortable answering.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:180px;">
                            <label for="residency_type">Visit type</label>
                            <select id="residency_type" name="residency_type">
                                <option value="">Prefer not to say</option>
                                <option value="Local Resident" @selected(old('residency_type') === 'Local Resident')>Local</option>
                                <option value="Domestic Tourist" @selected(old('residency_type') === 'Domestic Tourist')>Domestic</option>
                                <option value="Foreign Tourist" @selected(old('residency_type') === 'Foreign Tourist')>International</option>
                            </select>
                        </div>
                        <div class="field" style="flex:1; min-width:180px;">
                            <label for="travel_purpose">Purpose of trip</label>
                            <select id="travel_purpose" name="travel_purpose">
                                <option value="">Prefer not to say</option>
                                @foreach ($travelPurposes as $purpose)
                                    <option value="{{ $purpose }}" @selected(old('travel_purpose') === $purpose)>{{ $purpose }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:180px;">
                            <label for="actual_days_stayed">Days stayed</label>
                            <input type="number" id="actual_days_stayed" name="actual_days_stayed" min="1" max="365" value="{{ old('actual_days_stayed') }}" placeholder="e.g. 3">
                        </div>
                        <div class="field" style="flex:1; min-width:180px;">
                            <label for="estimated_total_spend">Total amount spent (&#8369;)</label>
                            <select id="estimated_total_spend" name="estimated_total_spend">
                                <option value="">Prefer not to say</option>
                                @foreach ($spendBrackets as $value => $label)
                                    <option value="{{ $value }}" @selected(old('estimated_total_spend') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <p class="field-hint">Include food, transport, activities, and shopping. Exclude accommodation paid separately in advance.</p>
                </div>
            </div>

            <div class="panel" data-survey-step="1">
                <div class="panel-head">
                    <div>
                        <span class="survey-step">02 · Your visit</span>
                        <h2 tabindex="-1">Your visit <span class="survey-optional-badge">Optional</span></h2>
                        <p>Optional &mdash; add only places you actually visited, even if your plans changed.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <x-tag-search
                        name="places_visited[]"
                        :items="$placeOptions"
                        :selected="$selectedPlaces"
                        label="Places you visited"
                        placeholder="Search places you visited…"
                    />

                    <p class="field-hint">Attractions and places you explored during your trip.</p>
                    <div class="field survey-visit-activities">
                        <label>Activities you participated in (optional)</label>
                        <div class="chip-checkbox-grid">
                            @foreach ($activityOptions as $activity)
                                <label class="field-check">
                                    <input type="checkbox" name="activities[]" value="{{ $activity }}" @checked(in_array($activity, old('activities', [])))>
                                    <span>{{ $activity }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    @include('partials.survey-stays')
                </div>
            </div>

            <div class="panel" data-survey-step="2">
                <div class="panel-head">
                    <div>
                        <span class="survey-step">03 · Your feedback</span>
                        <h2 tabindex="-1">How was your experience?</h2>
                        <p>Only your overall rating and recommendation answer are required.</p>
                    </div>
                </div>
                <div class="panel-body">
                    @include('partials.survey-rating', ['name' => 'overall_rating', 'label' => 'Overall satisfaction with your visit', 'required' => true])

                    <div class="field" style="margin-top:22px;">
                        <fieldset class="survey-recommend">
                        <legend>Would you recommend Davao to friends or family? <span>(required)</span></legend>
                        <div class="survey-recommend-options">
                            <label class="field-check radio-check" style="margin-top:0;">
                                <input type="radio" name="would_recommend" value="Yes" @checked(old('would_recommend') === 'Yes') required>
                                <span>Yes, definitely</span>
                            </label>
                            <label class="field-check radio-check" style="margin-top:0;">
                                <input type="radio" name="would_recommend" value="No" @checked(old('would_recommend') === 'No')>
                                <span>Probably not</span>
                            </label>
                        </div>
                        </fieldset>
                    </div>

                    <details class="survey-more" @if(old('destination_relevant') !== null || old('itinerary_useful') !== null || old('comments') || $errors->hasAny(['destination_relevant', 'itinerary_useful', 'comments'])) open @endif>
                        <summary>More about your experience <span>· optional</span></summary>
                        <p class="field-hint">If you didn’t use the recommendations or itinerary, choose “Not applicable”.</p>
                        @include('partials.survey-rating', ['name' => 'destination_relevant', 'label' => 'Relevance of recommended destinations', 'required' => false])
                        @include('partials.survey-rating', ['name' => 'itinerary_useful', 'label' => 'Usefulness of the suggested itinerary', 'required' => false])
                    <div class="field" style="margin-top:18px;">
                        <label for="comments">Any comments or suggestions? (optional)</label>
                        <textarea id="comments" name="comments" rows="3" maxlength="500" placeholder="What did you love? What could DOT improve?">{{ old('comments') }}</textarea>
                        <p class="field-hint">Up to 500 characters. Please leave out names and contact details.</p>
                    </div>
                    </details>

                    <button type="submit" class="btn survey-submit" data-survey-submit>Submit feedback &rarr;</button>
                </div>
            </div>
            </div>
            <div class="survey-navigation" data-survey-navigation hidden>
                <button type="button" class="btn btn-outline" data-survey-back>← Back</button>
                <button type="button" class="survey-skip" data-survey-next data-survey-skip>Skip this step</button>
                <span data-survey-step-label>Step 1 of 3</span>
                <button type="button" class="btn btn-primary" data-survey-next data-survey-next-primary>Next: your visit →</button>
            </div>
        </form>
        </div>
        </div>
    </div>
</div>
</div>
<script src="{{ asset('js/exit-survey-wizard.js') }}?v={{ filemtime(public_path('js/exit-survey-wizard.js')) }}" defer></script>
<script src="{{ asset('js/davo-planner-video.js') }}?v={{ filemtime(public_path('js/davo-planner-video.js')) }}" defer></script>
@endsection
