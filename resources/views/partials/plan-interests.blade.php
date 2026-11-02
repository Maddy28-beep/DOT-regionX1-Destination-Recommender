{{--
    Step 2 of Plan My Trip: why they are coming, what they like, and what they want nearby.

    The purpose is a radio group drawn as chips; interests and amenities are checkbox groups drawn as cards.
    The groups keep data-checkbox-group, so "select at least one" is enforced exactly as before.

    $preference, $travelPurposes, $activityOptions, $amenityOptions, $selectedActivities, $selectedAmenities
--}}
@php
    $purposeIcons = [
        'Leisure' => 'sun', 'Business' => 'briefcase', 'Visiting Friends/Family' => 'users', 'Educational' => 'graduation',
        'Medical' => 'medical', 'Religious/Pilgrimage' => 'church', 'Other' => 'dots',
    ];
    $activityIcons = [
        'Beach & Island' => 'umbrella', 'Nature & Adventure' => 'mountain', 'Cultural Heritage' => 'landmark', 'Wildlife' => 'feather',
        'Food Tourism' => 'utensils', 'Shopping & Souvenirs' => 'bag', 'Hiking & Trekking' => 'boot', 'Relaxation & Wellness' => 'leaf',
    ];
    $amenityIcons = [
        'Parking Area' => 'parking', 'Restaurant' => 'utensils', 'Swimming Pool' => 'pool', 'Wi-Fi' => 'wifi',
        'Restroom' => 'restroom', 'Accessibility Ramp' => 'accessibility', 'Air Conditioning' => 'snowflake',
    ];
    $chosenPurpose = old('travel_purpose', $preference->travel_purpose);
@endphp

<fieldset class="pc-group plan-purpose">
    <legend>Purpose of travel</legend>
    <div class="pc-chips">
        @foreach ($travelPurposes as $purpose)
            <label class="pc-chip pc-chip--icon">
                <input type="radio" name="travel_purpose" value="{{ $purpose }}" @checked($chosenPurpose === $purpose) required>
                <x-icon :name="$purposeIcons[$purpose] ?? 'dots'" />
                <span>{{ $purpose }}</span>
            </label>
        @endforeach
    </div>
</fieldset>

<div class="pc-interest-columns">
<div class="field pc-group" style="margin-top:0;" data-checkbox-group="activities" role="group" aria-labelledby="pcActivitiesHeading">
    <div class="pc-interest-heading">
        <h3 class="pc-legend" id="pcActivitiesHeading">Interests &amp; activities <span class="field-required-hint">Select at least one</span></h3>
        <span class="pc-selection-count" data-selection-count="activities" hidden></span>
    </div>
    <div class="pc-checks">
        @foreach ($activityOptions as $activity)
            <label class="pc-check">
                <input type="checkbox" name="activities[]" value="{{ $activity }}" @checked(in_array($activity, old('activities', $selectedActivities)))>
                <span class="pc-check__icon"><x-icon :name="$activityIcons[$activity] ?? 'star'" /></span>
                <span class="pc-check__text">{{ $activity }}</span>
                <span class="pc-check__box"><x-icon name="check" /></span>
            </label>
        @endforeach
    </div>
</div>

<div class="field pc-group" data-checkbox-group="amenities" role="group" aria-labelledby="pcAmenitiesHeading">
    <div class="pc-interest-heading">
        <h3 class="pc-legend" id="pcAmenitiesHeading">Preferred amenities <span class="field-required-hint">Select at least one</span></h3>
        <span class="pc-selection-count" data-selection-count="amenities" hidden></span>
    </div>
    <div class="pc-checks">
        @foreach ($amenityOptions as $amenity)
            <label class="pc-check">
                <input type="checkbox" name="amenities[]" value="{{ $amenity }}" @checked(in_array($amenity, old('amenities', $selectedAmenities)))>
                <span class="pc-check__icon"><x-icon :name="$amenityIcons[$amenity] ?? 'star'" /></span>
                <span class="pc-check__text">{{ $amenity }}</span>
                <span class="pc-check__box"><x-icon name="check" /></span>
            </label>
        @endforeach
    </div>
</div>
</div>
<div class="pc-picks-summary" data-picks-summary hidden>
    <span class="pc-picks-summary__icon" aria-hidden="true"><x-icon name="check" /></span>
    <p><strong>Your picks:</strong> <span data-picks-text></span></p>
</div>
<span class="sr-only" data-selection-status role="status" aria-live="polite" aria-atomic="true"></span>
