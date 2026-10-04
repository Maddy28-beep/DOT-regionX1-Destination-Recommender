@extends('layouts.app')

@section('title', 'Exit Survey — ExploreDVO')

@section('content')
<link rel="stylesheet" href="{{ asset('css/exit-survey.css') }}?v={{ filemtime(public_path('css/exit-survey.css')) }}">
<div class="exit-survey-page">
<div class="page-head">
    <div class="container">
        <span class="poster-kicker" style="font-size:1.05rem;">how was your trip?</span>
        <h1 class="page-title" style="font-size:1.9rem; margin:0;">Visitor Exit Survey</h1>
        <p>Finished your trip? Tell us how it went and help improve tourism in Davao.</p>
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

        <div class="privacy-note" style="margin-top:0;">
            <x-icon name="shield-check" />
            <p>
                Your responses are <strong>anonymous</strong>. This survey does not collect your name, email, or
                account information, and is not linked to your ExploreDVO profile if you have one. Data is handled
                per the Philippine Data Privacy Act of 2012 (RA 10173) and used only for tourism analytics and
                service improvement.
            </p>
        </div>

        <form method="POST" action="{{ route('exit-survey.store') }}">
            @csrf
            @if ($isTest ?? false)
                <input type="hidden" name="test" value="1">
                <div class="alert alert-error" role="note" style="margin-bottom:18px;">
                    <strong>Test mode.</strong> Responses sent from this page are kept separate and are not counted in any
                    results or in the association rules.
                </div>
            @endif

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <span class="survey-step">01 · Trip details</span>
                        <h2>About Your Trip</h2>
                        <p>These details are optional. Share what you’re comfortable answering.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="field">
                        <label for="origin">Where are you visiting from?</label>
                        <input type="text" id="origin" name="origin" maxlength="150" value="{{ old('origin') }}" placeholder="e.g. Cebu City, Philippines">
                        <p class="field-hint">Helps DOT Region XI understand where visitors are travelling from.</p>
                    </div>

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
                    <p class="field-hint">Your total spend for the whole trip &mdash; food, transport, activities, and shopping &mdash; not accommodation, if you paid for that separately in advance.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <span class="survey-step">02 · Places & activities</span>
                        <h2>Your Visit</h2>
                        <p>Optional &mdash; add only places you actually visited, even if your plans changed.</p>
                        <p class="field-hint" style="margin-top:6px;"><strong>Tip:</strong> adding two or more places helps us learn which places go well together.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <x-tag-search
                        name="places_visited[]"
                        :items="$placeOptions"
                        :selected="$selectedPlaces"
                        label="Where did you go during your trip?"
                        placeholder="Search places you visited…"
                    />

                    <div class="field" style="margin-top:22px;">
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
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <span class="survey-step">03 · Your feedback</span>
                        <h2>Your Experience</h2>
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

                    <button type="submit" class="btn survey-submit">Submit feedback &rarr;</button>
                </div>
            </div>
        </form>
    </div>
</div>
</div>
@endsection
