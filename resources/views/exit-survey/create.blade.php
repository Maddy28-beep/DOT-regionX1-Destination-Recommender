@extends('layouts.app')

@section('title', 'Exit Survey — ExploreDVO')

@section('content')
<link rel="stylesheet" href="{{ asset('css/exit-survey.css') }}?v={{ filemtime(public_path('css/exit-survey.css')) }}">
<div class="exit-survey-page survey-quick">
    <div class="page-head">
        <div class="container survey-welcome">
            <img class="survey-welcome__davo" src="{{ asset('images/davo-survey-v2.webp') }}" alt="" width="100" height="120">
            <div>
                <span class="survey-step">Visitor exit survey</span>
                <h1 class="page-title">How was your trip?</h1>
                <p>Two quick answers. Share more only if you want to.</p>
            </div>
        </div>
    </div>
    <div class="section-tight">
        <div class="container survey-container">
            @if ($errors->any())
                <div class="alert alert-error" role="alert">
                    <strong>Please check your answers.</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('exit-survey.store') }}">
                @csrf
                <div class="hp-field" aria-hidden="true">
                    <label for="{{ \App\Http\Controllers\ExitSurveyController::HONEYPOT_FIELD }}">Leave this field empty</label>
                    <input type="text" id="{{ \App\Http\Controllers\ExitSurveyController::HONEYPOT_FIELD }}" name="{{ \App\Http\Controllers\ExitSurveyController::HONEYPOT_FIELD }}" value="" tabindex="-1" autocomplete="off">
                </div>
                @if ($isTest ?? false)
                    <input type="hidden" name="test" value="1">
                    <div class="alert alert-error" role="note">
                        <strong>Test mode.</strong> These responses are kept separate and are not counted in results or association rules.
                    </div>
                @endif

                <div class="panel">
                    <div class="panel-body survey-quick-body">
                        @include('partials.survey-rating', ['name' => 'overall_rating', 'label' => 'How was your overall visit?', 'required' => true])

                        <fieldset class="survey-recommend">
                            <legend>Would you recommend Davao to friends or family? <span>(required)</span></legend>
                            <div class="survey-recommend-options">
                                <label class="field-check radio-check">
                                    <input type="radio" name="would_recommend" value="Yes" @checked(old('would_recommend') === 'Yes') required>
                                    <span>Yes</span>
                                </label>
                                <label class="field-check radio-check">
                                    <input type="radio" name="would_recommend" value="No" @checked(old('would_recommend') === 'No')>
                                    <span>No</span>
                                </label>
                            </div>
                            @error('would_recommend')<p class="survey-field-error">{{ $message }}</p>@enderror
                        </fieldset>

                        <fieldset class="survey-spend" aria-describedby="survey-spend-hint">
                            <legend>About how much did you spend? <span>(optional)</span></legend>
                            <p id="survey-spend-hint" class="field-hint">Choose a range for your whole trip: food, transport, activities and shopping. Exclude accommodation paid separately in advance. This helps DOT understand visitor spending.</p>
                            <div class="survey-spend-options">
                                @foreach ($spendBrackets as $value => $label)
                                    <label>
                                        <input type="radio" name="estimated_total_spend" value="{{ $value }}" @checked(old('estimated_total_spend') === $value)>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                                <label>
                                    <input type="radio" name="estimated_total_spend" value="" @checked(old('estimated_total_spend') === '')>
                                    <span>Prefer not to say</span>
                                </label>
                            </div>
                            @error('estimated_total_spend')<p class="survey-field-error">{{ $message }}</p>@enderror
                        </fieldset>

                        @include('partials.survey-stays')

                        <p class="survey-extra-intro">You can submit now, or add a little more below.</p>
                        <div class="survey-extras">
                            <details class="survey-extra" @if(old('places_visited', []) || old('activities', []) || $errors->hasAny(['places_visited', 'places_visited.*', 'activities', 'activities.*'])) open @endif>
                                <summary>Places &amp; activities <span>Optional</span></summary>
                                <div class="survey-extra-body">
                                    <p class="field-hint">Add only places you actually visited. You can leave this blank.</p>
                                    <x-tag-search name="places_visited[]" :items="$placeOptions" :selected="$selectedPlaces" label="Where did you go?" placeholder="Search places you visited…" />
                                    <fieldset class="survey-activities">
                                        <legend>What did you do?</legend>
                                        <div class="chip-checkbox-grid">
                                            @foreach ($activityOptions as $activity)
                                                <label class="field-check">
                                                    <input type="checkbox" name="activities[]" value="{{ $activity }}" @checked(in_array($activity, old('activities', [])))>
                                                    <span>{{ $activity }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                </div>
                            </details>

                            <details class="survey-extra" @if(old('destination_relevant') !== null || old('itinerary_useful') !== null || old('comments') || $errors->hasAny(['destination_relevant', 'itinerary_useful', 'comments'])) open @endif>
                                <summary>Comments &amp; recommendations <span>Optional</span></summary>
                                <div class="survey-extra-body">
                                    <div class="field">
                                        <label for="comments">What went well? What could be better?</label>
                                        <textarea id="comments" name="comments" rows="3" maxlength="500" placeholder="Share anything you’d like DOT to know…">{{ old('comments') }}</textarea>
                                        <p class="field-hint">Up to 500 characters. Please leave out names and contact details.</p>
                                    </div>
                                    <p class="field-hint">Used our trip planner? You can rate it here, or leave these unanswered.</p>
                                    @include('partials.survey-rating', ['name' => 'destination_relevant', 'label' => 'Relevance of recommended destinations', 'required' => false])
                                    @include('partials.survey-rating', ['name' => 'itinerary_useful', 'label' => 'Usefulness of the suggested itinerary', 'required' => false])
                                </div>
                            </details>
                        </div>

                        <button type="submit" class="btn survey-submit">Send feedback</button>
                        <p class="survey-privacy"><x-icon name="shield-check" /> No name or email needed. Your feedback helps improve tourism services.</p>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
