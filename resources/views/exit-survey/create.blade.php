@extends('layouts.app')

@section('title', 'Exit Survey — ExploreDVO')

@section('content')
<div class="page-head">
    <div class="container">
        <span class="poster-kicker" style="font-size:1.05rem;">how was your trip?</span>
        <h1 class="page-title" style="font-size:1.9rem; margin:0;">Visitor Exit Survey</h1>
        <p>A short, anonymous survey to help DOT Region XI improve tourism services in the Davao Region.</p>
    </div>
</div>

<div class="section-tight">
    <div class="container" style="max-width:720px;">

        @if ($errors->any())
            <div class="alert alert-error">
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

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>About Your Trip</h2>
                        <p>A few quick questions for DOT Region XI's tourism statistics.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="field">
                        <label for="origin">Where are you visiting from?</label>
                        <input type="text" id="origin" name="origin" value="{{ old('origin') }}" placeholder="e.g. Cebu City, Philippines">
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
                            <label for="estimated_daily_spend">Spend per day (&#8369;)</label>
                            <input type="number" id="estimated_daily_spend" name="estimated_daily_spend" min="0" step="0.01" value="{{ old('estimated_daily_spend') }}" placeholder="e.g. 1500">
                        </div>
                    </div>
                    <p class="field-hint">Daily spend includes food, transport, activities, and shopping &mdash; not accommodation, if you paid for that separately in advance.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Your Visit</h2>
                        <p>Optional &mdash; search and add the places you went.</p>
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
                        <h2>Your Experience</h2>
                        <p>How would you rate your trip? (optional, except overall satisfaction)</p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="rating-row">
                        <span class="rating-row-label"><strong>Overall satisfaction with your visit</strong></span>
                        @include('partials.star-input', ['name' => 'overall_rating', 'required' => true])
                    </div>
                    <div class="rating-row">
                        <span class="rating-row-label">Relevance of recommended destinations</span>
                        @include('partials.star-input', ['name' => 'destination_relevant'])
                    </div>
                    <div class="rating-row">
                        <span class="rating-row-label">Usefulness of the suggested itinerary</span>
                        @include('partials.star-input', ['name' => 'itinerary_useful'])
                    </div>

                    <div class="field" style="margin-top:22px;">
                        <label>Would you recommend the Davao Region to friends or family?</label>
                        <div style="display:flex; gap:20px; margin-top:8px;">
                            <label class="field-check radio-check" style="margin-top:0;">
                                <input type="radio" name="would_recommend" value="Yes" @checked(old('would_recommend') === 'Yes') required>
                                <span>Yes, definitely</span>
                            </label>
                            <label class="field-check radio-check" style="margin-top:0;">
                                <input type="radio" name="would_recommend" value="No" @checked(old('would_recommend') === 'No')>
                                <span>Probably not</span>
                            </label>
                        </div>
                    </div>

                    <div class="field" style="margin-top:18px;">
                        <label for="comments">Any comments or suggestions? (optional)</label>
                        <textarea id="comments" name="comments" rows="3" placeholder="What did you love? What could DOT improve?">{{ old('comments') }}</textarea>
                    </div>

                    <button type="submit" class="btn btn-accent btn-block" style="margin-top:20px;">Submit Survey</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
