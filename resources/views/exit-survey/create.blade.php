@extends('layouts.app')

@section('title', 'Exit Survey — ExploreDVO')

@section('content')
<div class="page-head">
    <div class="container">
        <h1>Visitor Exit Survey</h1>
        <p>Help DOT Region XI improve tourism services and destination management in the Davao Region.</p>
    </div>
</div>

<div class="section-tight">
    <div class="container" style="max-width:720px;">

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                <ul style="margin:0; padding-left:18px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Data Privacy Notice</h2>
                    <p>Your responses are <strong>anonymous</strong>. This survey does not collect your name, email, or account information, and is not linked to your ExploreDVO profile if you have one. Data is handled per the Philippine Data Privacy Act of 2012 (RA 10173) and used only for tourism analytics and service improvement.</p>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('exit-survey.store') }}">
            @csrf

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>About You</h2>
                        <p>Help us understand who's visiting the Davao Region (optional).</p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="filter-inline" style="align-items:start;">
                        <div class="field" style="flex:1; min-width:200px;">
                            <label for="residency_type">I am a:</label>
                            <select id="residency_type" name="residency_type">
                                <option value="">Prefer not to say</option>
                                <option value="Local Resident" @selected(old('residency_type') === 'Local Resident')>Local Resident</option>
                                <option value="Domestic Tourist" @selected(old('residency_type') === 'Domestic Tourist')>Domestic Tourist</option>
                                <option value="Foreign Tourist" @selected(old('residency_type') === 'Foreign Tourist')>Foreign Tourist</option>
                            </select>
                        </div>
                        <div class="field" style="flex:1; min-width:200px;">
                            <label for="visitor_type">My visit type:</label>
                            <select id="visitor_type" name="visitor_type">
                                <option value="">Prefer not to say</option>
                                <option value="First-time Visitor" @selected(old('visitor_type') === 'First-time Visitor')>First-time Visitor</option>
                                <option value="Returning Visitor" @selected(old('visitor_type') === 'Returning Visitor')>Returning Visitor</option>
                                <option value="Regular / Local" @selected(old('visitor_type') === 'Regular / Local')>Regular / Local</option>
                            </select>
                        </div>
                        <div class="field" style="flex:1; min-width:200px;">
                            <label for="origin">Place of origin (optional)</label>
                            <input type="text" id="origin" name="origin" value="{{ old('origin') }}" placeholder="e.g. Cebu City, Philippines">
                        </div>
                    </div>

                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:200px;">
                            <label for="travel_purpose">Purpose of this trip:</label>
                            <select id="travel_purpose" name="travel_purpose">
                                <option value="">Prefer not to say</option>
                                @foreach (['Leisure', 'Business', 'Visiting Friends/Family', 'Educational', 'Medical', 'Religious/Pilgrimage', 'Other'] as $purpose)
                                    <option value="{{ $purpose }}" @selected(old('travel_purpose') === $purpose)>{{ $purpose }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="flex:1; min-width:200px;">
                            <label for="actual_days_stayed">How many days did you stay in Davao Region?</label>
                            <input type="number" id="actual_days_stayed" name="actual_days_stayed" min="1" max="365" value="{{ old('actual_days_stayed') }}" placeholder="e.g. 3">
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Your Visit</h2>
                        <p>What did you experience during your trip? (optional &mdash; select all that apply)</p>
                    </div>
                </div>
                <div class="panel-body">
                    @foreach ($placeGroups as $kind => $group)
                        @if ($group['items']->isNotEmpty())
                            <div class="field" style="margin-top:{{ $loop->first ? '0' : '18' }}px;">
                                <label>{{ $group['label'] }} visited</label>
                                <div class="checkbox-grid">
                                    @foreach ($group['items'] as $item)
                                        <label class="field-check">
                                            <input type="checkbox" name="places_visited[]" value="{{ $kind }}:{{ $item->id }}" @checked(in_array($kind.':'.$item->id, old('places_visited', [])))>
                                            <span>{{ $item->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach

                    <div class="field" style="margin-top:18px;">
                        <label>Activities you participated in</label>
                        <div class="checkbox-grid">
                            @foreach (['Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism', 'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness'] as $activity)
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
                        <h2>Rate Your Experience</h2>
                        <p>How would you rate the following aspects of your trip? (optional)</p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="rating-row">
                        <span class="rating-row-label">Relevance of recommended destinations</span>
                        @include('partials.star-input', ['name' => 'destination_relevant'])
                    </div>
                    <div class="rating-row">
                        <span class="rating-row-label">Usefulness of the suggested itinerary</span>
                        @include('partials.star-input', ['name' => 'itinerary_useful'])
                    </div>
                    <div class="rating-row">
                        <span class="rating-row-label">Quality of attractions visited</span>
                        @include('partials.star-input', ['name' => 'attractions_quality'])
                    </div>
                    <div class="rating-row">
                        <span class="rating-row-label">Accommodation experience</span>
                        @include('partials.star-input', ['name' => 'accommodation_rating'])
                    </div>
                    <div class="rating-row">
                        <span class="rating-row-label">Transportation experience</span>
                        @include('partials.star-input', ['name' => 'transport_rating'])
                    </div>

                    <div class="rating-row" style="margin-top:10px; border-top:2px solid var(--border); padding-top:16px;">
                        <span class="rating-row-label"><strong>Overall satisfaction with your visit</strong></span>
                        @include('partials.star-input', ['name' => 'overall_rating', 'required' => true])
                    </div>

                    <div class="field" style="margin-top:22px;">
                        <label>Would you recommend the Davao Region to friends or family?</label>
                        <div style="display:flex; gap:20px; margin-top:8px;">
                            <label class="field-check" style="margin-top:0;">
                                <input type="radio" name="would_recommend" value="Yes" @checked(old('would_recommend') === 'Yes') required>
                                <span>Yes, definitely</span>
                            </label>
                            <label class="field-check" style="margin-top:0;">
                                <input type="radio" name="would_recommend" value="No" @checked(old('would_recommend') === 'No')>
                                <span>Probably not</span>
                            </label>
                        </div>
                    </div>

                    <div class="field" style="margin-top:18px;">
                        <label for="comments">Any comments or suggestions? (optional)</label>
                        <textarea id="comments" name="comments" rows="3" placeholder="What did you love? What could DOT improve? Any specific attractions or experiences worth highlighting?">{{ old('comments') }}</textarea>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" style="margin-top:20px;">Submit Survey</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
