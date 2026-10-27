{{--
    Step 3 of the trip form: what the traveller will spend and where they will sleep.

    Every choice is a card or a chip over a real radio button, so the form posts exactly what it always did
    (budget, accommodation_pref, resort_setting) and works without JavaScript. A choice is already made for
    the two required questions, so someone who only wants a plan can go straight on.

    The resort settings offered are only those a bookable resort is filed under ($resortSettings), so no chip
    leads to an empty answer. They appear when "Resort" is picked (app.js, [data-resort-toggle]) and the server
    clears a stray one for any other stay (TripPlannerController::update).

    $preference, $resortSettings
--}}
@php
    $budgetNow = old('budget', $preference->budget) ?: 'Mid-range';
    $stayNow = old('accommodation_pref', $preference->accommodation_pref) ?: 'Any';
    $settingNow = (string) old('resort_setting', $preference->resort_setting);

    $budgetNotes = [
        'Budget-Friendly' => 'Simple stays, local eats, free or low-fee attractions.',
        'Mid-range' => 'A comfortable balance of value and experience.',
        'Premium' => 'Top-rated stays and signature experiences.',
    ];
    $stayNames = ['Any' => 'Any', 'Hotel' => 'Hotel', 'Resort' => 'Resort', 'Beach Resort' => 'Beach Resort', 'Homestay' => 'Homestay / Self-catering'];
@endphp

<div class="plan-section">
    <h3 class="plan-section__title"><x-icon name="tag" /> Budget &amp; stay</h3>

    <fieldset class="pc-group">
        <legend>Spending style</legend>
        <p class="pc-help">Used to match stays, dining and activities to your budget.</p>
        <div class="pc-cards">
            @foreach ($budgetNotes as $value => $note)
                <label class="pc-card">
                    <input type="radio" name="budget" value="{{ $value }}" @checked($budgetNow === $value) required>
                    <span class="pc-card__text"><strong>{{ $value }}</strong><small>{{ $note }}</small></span>
                    <span class="pc-card__tick" aria-hidden="true"><x-icon name="check" /></span>
                </label>
            @endforeach
        </div>
    </fieldset>

    <fieldset class="pc-group">
        <legend>Where would you like to stay?</legend>
        <p class="pc-help">Choosing a resort lets you narrow it down by setting.</p>
        <div class="pc-chips">
            @foreach ($stayNames as $value => $name)
                <label class="pc-chip">
                    <input type="radio" name="accommodation_pref" value="{{ $value }}" data-resort-toggle @checked($stayNow === $value) required>
                    <span>{{ $name }}</span>
                </label>
            @endforeach
        </div>

        @if ($resortSettings !== [])
            <div class="pc-sub" data-resort-setting-field @unless ($stayNow === 'Resort') hidden @endunless>
                <strong>What kind of resort? <span class="field-optional-hint">(optional)</span></strong>
                <span class="pc-help">Only settings we have resorts for are shown.</span>
                <div class="pc-chips">
                    <label class="pc-chip">
                        <input type="radio" name="resort_setting" value="" @checked($settingNow === '')>
                        <span>Any resort</span>
                    </label>
                    @foreach ($resortSettings as $setting)
                        <label class="pc-chip">
                            <input type="radio" name="resort_setting" value="{{ $setting }}" @checked($settingNow === $setting)>
                            <span>{{ $setting === 'All-Inclusive' ? 'All Inclusive Resort' : $setting.' Resort' }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
    </fieldset>
</div>
