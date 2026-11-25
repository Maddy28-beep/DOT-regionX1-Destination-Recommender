@php
    $confirmed = $restaurant->foodPreferences()->confirmed()->pluck('preference')->all();
    $evidence = $restaurant->foodPreferences->keyBy('preference');
@endphp
<section class="side-card about-card food-preferences" aria-labelledby="food-preferences-title">
    <h3 id="food-preferences-title">Food preferences</h3>
    <p class="food-preferences__intro">Based on published sources. Ask the restaurant about ingredients and preparation before ordering.</p>
    @foreach (\App\Models\RestaurantFoodPreference::OPTIONS as $key => $label)
        @php $record = $evidence->get($key); $isConfirmed = in_array($key, $confirmed, true); @endphp
        <div class="food-preferences__row">
            <div class="food-preferences__heading"><strong>{{ $label }}</strong><span class="food-preferences__status {{ $isConfirmed ? 'is-confirmed' : '' }}">{{ $isConfirmed ? ($key === 'halal_certified' ? 'Confirmed' : 'Available') : 'Not confirmed' }}</span></div>
            @if ($isConfirmed)
                <p>{{ $record->evidence }}</p>
                @if ($key === 'halal_certified')<p>{{ $record->certifier }} · {{ $record->certificate_reference }}<br>{{ $record->coverage }}<br>Valid until {{ $record->expires_on->format('j M Y') }}</p>@endif
                <p><a href="{{ $record->source_url }}" target="_blank" rel="noopener noreferrer">{{ $record->source_name }}</a> · Checked {{ $record->checked_on->format('j M Y') }}</p>
            @endif
        </div>
    @endforeach
    <p class="food-preferences__intro">“Not confirmed” means we do not have sufficient current evidence. It does not mean the restaurant cannot offer it. DOT accreditation is separate from halal certification.</p>
</section>
