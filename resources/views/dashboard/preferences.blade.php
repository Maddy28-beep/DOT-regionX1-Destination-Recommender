@extends('layouts.app')

@section('title', 'Travel Preferences — ExploreDVO')

@section('content')
@php
    $selectedActivities = $preference->relationLoaded('activities') ? $preference->activities->pluck('activity')->all() : [];
    $selectedAmenities = $preference->relationLoaded('amenities') ? $preference->amenities->pluck('amenity')->all() : [];

    $activityOptions = ['Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Wildlife', 'Food Tourism', 'Shopping & Souvenirs', 'Hiking & Trekking', 'Relaxation & Wellness'];
    $amenityOptions = ['Parking Area', 'Restaurant', 'Swimming Pool', 'Wi-Fi', 'Restroom', 'Accessibility Ramp', 'Air Conditioning'];
    $travelTypes = ['Solo' => 'Solo', 'Couple' => 'Couple', 'Family' => 'Family', 'Friends' => 'Friends / Group', 'Business' => 'Business'];
    $travelPurposes = ['Leisure', 'Business', 'Visiting Friends/Family', 'Educational', 'Medical', 'Religious/Pilgrimage', 'Other'];
    $visitorTypes = ['First-time Visitor', 'Returning Visitor', 'Regular / Local'];
@endphp

<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <h1>Travel Preferences</h1>
                <div class="sub">Tell us how you like to travel &mdash; this powers your personalized recommendations once the recommendation engine is live.</div>
            </div>
            <a href="{{ route('tourist.dashboard') }}" class="btn btn-outline">Back to My Trip</a>
        </div>
    </div>

    <div class="dash-body">
        <div class="container">
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
                        <h2>Your Travel Preference Survey</h2>
                        <p>Update this anytime before planning a new trip. Your answers are saved to your account.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <form method="POST" action="{{ route('tourist.preferences.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="filter-inline" style="align-items:start;">
                            <div class="field" style="flex:1; min-width:160px;">
                                <label for="travel_days">Number of travel days</label>
                                <input type="number" id="travel_days" name="travel_days" min="1" max="30" value="{{ old('travel_days', $preference->travel_days ?? 3) }}" required>
                            </div>
                            <div class="field" style="flex:1; min-width:200px;">
                                <label for="start_date">Planned arrival date (optional)</label>
                                <input type="date" id="start_date" name="start_date" value="{{ old('start_date', optional($preference->start_date)->format('Y-m-d')) }}">
                            </div>
                        </div>

                        <div class="filter-inline" style="align-items:start; margin-top:14px;">
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="travel_type">Who are you traveling with?</label>
                                <select id="travel_type" name="travel_type" required>
                                    @foreach ($travelTypes as $value => $label)
                                        <option value="{{ $value }}" @selected(old('travel_type', $preference->travel_type) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="travel_purpose">Purpose of travel</label>
                                <select id="travel_purpose" name="travel_purpose">
                                    <option value="">Prefer not to say</option>
                                    @foreach ($travelPurposes as $purpose)
                                        <option value="{{ $purpose }}" @selected(old('travel_purpose', $preference->travel_purpose) === $purpose)>{{ $purpose }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="visitor_type">Is this your first visit to Davao?</label>
                                <select id="visitor_type" name="visitor_type">
                                    <option value="">Prefer not to say</option>
                                    @foreach ($visitorTypes as $type)
                                        <option value="{{ $type }}" @selected(old('visitor_type', $preference->visitor_type) === $type)>{{ $type }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="filter-inline" style="align-items:start; margin-top:14px;">
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="budget">Budget</label>
                                <select id="budget" name="budget" required>
                                    @foreach (['Budget-Friendly', 'Mid-range', 'Premium'] as $b)
                                        <option value="{{ $b }}" @selected(old('budget', $preference->budget) === $b)>{{ $b }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="accommodation_pref">Accommodation preference</label>
                                <select id="accommodation_pref" name="accommodation_pref" required>
                                    @foreach (['Any', 'Beach Resort', 'Hotel', 'Homestay', 'Hostel'] as $a)
                                        <option value="{{ $a }}" @selected(old('accommodation_pref', $preference->accommodation_pref) === $a)>{{ $a === 'Homestay' ? 'Homestay / Self-catering' : $a }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" style="flex:1; min-width:180px;">
                                <label for="distance_pref">Preferred travel distance</label>
                                <select id="distance_pref" name="distance_pref" required>
                                    <option value="near" @selected(old('distance_pref', $preference->distance_pref) === 'near')>Nearby (within city)</option>
                                    <option value="moderate" @selected(old('distance_pref', $preference->distance_pref) === 'moderate')>Moderate distance</option>
                                    <option value="far" @selected(old('distance_pref', $preference->distance_pref) === 'far')>Far / willing to travel</option>
                                </select>
                            </div>
                        </div>

                        <div class="field" style="margin-top:18px;">
                            <label>Interests &amp; activities</label>
                            <div class="checkbox-grid">
                                @foreach ($activityOptions as $activity)
                                    <label class="field-check">
                                        <input type="checkbox" name="activities[]" value="{{ $activity }}" @checked(in_array($activity, old('activities', $selectedActivities)))>
                                        <span>{{ $activity }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="field" style="margin-top:18px;">
                            <label>Preferred amenities</label>
                            <div class="checkbox-grid">
                                @foreach ($amenityOptions as $amenity)
                                    <label class="field-check">
                                        <input type="checkbox" name="amenities[]" value="{{ $amenity }}" @checked(in_array($amenity, old('amenities', $selectedAmenities)))>
                                        <span>{{ $amenity }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="field" style="margin-top:18px;">
                            <label for="accessibility_notes">Anything else we should know? (optional)</label>
                            <textarea id="accessibility_notes" name="accessibility_notes" rows="3" placeholder="e.g. traveling with young children, prefer slower-paced itineraries">{{ old('accessibility_notes', $preference->accessibility_notes) }}</textarea>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top:20px;">Save Preferences</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
