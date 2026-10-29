{{--
    The two cards under the day's map: the accredited tour operators near the traveller's starting point (only when they
    chose to be picked up) and "Planned around", the choices that shaped this plan, read straight from the preference
    it was built from.

    The operators are suggestions to contact, not a booking and not a promise: the catalogue holds who they are and
    how to reach them, nothing about where they collect travellers, when, or for how much. The card says so.

    $preference, $operators (TourOperatorSuggester::near(), empty unless the traveller chose a pick-up)
--}}
@use('App\Services\Recommendation\TransportMode')
@php
    $stay = $preference?->accommodation_pref;
    $stayLabel = match (true) {
        blank($stay) || $stay === 'Any' => null,
        $stay === 'Resort' && filled($preference->resort_setting) => ($preference->resort_setting === 'All-Inclusive' ? 'All Inclusive' : $preference->resort_setting).' Resort',
        $stay === 'Homestay' => 'Homestay / Self-catering',
        default => $stay,
    };
    $distanceNames = ['near' => 'Close Proximity', 'moderate' => 'Commutable', 'far' => 'Remote'];
    $radius = \App\Services\Recommendation\ContentBasedRecommendationService::RANGE_RADIUS_KM[$preference?->distance_pref] ?? null;
    $transport = $preference?->transport_pref;
@endphp

@if (($operators ?? collect())->isNotEmpty())
    @php
        $sampleData = app(\App\Support\SampleData::class);
        $anySampleOperator = $operators->contains(fn ($row) => $sampleData->isSample($row['operator']));
    @endphp
    <section class="itin-side__card itin-ops" id="itin-ops" aria-labelledby="itin-ops-title">
        <h2 id="itin-ops-title"><x-icon name="van" /> Tour operators for your pick-up</h2>
        <p class="itin-ops__lead">
            @if ($anySampleOperator)
                Close to your start. Operators marked sample data are not DOT-verified. They set the time, place and price, so confirm with them first.
            @else
                DOT-accredited, close to your start. They set the time, place and price, so confirm with them first.
            @endif
        </p>
        <ul class="itin-ops__list">
            @foreach ($operators->values() as $row)
                @php
                    $operator = $row['operator'];
                    $onMap = $operator->latitude !== null && $operator->longitude !== null;
                    $mapsUrl = 'https://www.google.com/maps/search/?api=1&query='.($onMap
                        ? $operator->latitude.','.$operator->longitude
                        : rawurlencode($operator->name.' '.($operator->location ?: 'Davao')));
                    // Some entries list two numbers ("0917... / (082) ..."): the first is the one to call.
                    $number = trim((string) preg_split('/[\/;,]/', (string) $operator->contact_number)[0]);
                    $dial = preg_replace('/[^0-9+]/', '', $number);
                @endphp
                <li class="itin-op">
                    <div class="itin-op__main">
                        <a class="itin-op__name" href="{{ route('tour-operators.show', $operator) }}">{{ $operator->name }}</a>
                        @if ($sampleData->isSample($operator))
                            <x-sample-tag label="Sample data, not DOT-verified" title="This operator's accreditation number is a placeholder, not an official DOT number." />
                        @endif
                        <span class="itin-op__meta">
                            {{ $row['km'] !== null ? $fmtKm($row['km']).' km away' : ($operator->location ?: 'Davao region') }}
                            &middot; <a href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer">Map<span class="sr-only"> for {{ $operator->name }} (opens in a new tab)</span></a>
                        </span>
                    </div>
                    @if ($dial !== '' && $sampleData->isSample($operator))
                        {{-- Not verified, so shown but not offered as a call link. --}}
                        <span class="itin-op__phone"><x-icon name="phone" /> {{ $number }}</span>
                    @elseif ($dial !== '')
                        <a class="itin-op__phone" href="tel:{{ $dial }}"><x-icon name="phone" /> {{ $number }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
        <a class="itin-ops__more" href="{{ route('tour-operators.index') }}">See all tour operators &rarr;</a>
    </section>
@endif

@if ($preference && (isset($distanceNames[$preference->distance_pref]) || $stayLabel || filled($transport)))
    <section class="itin-side__card itin-around" aria-labelledby="itin-around-title">
        <header class="itin-around__head">
            <h2 id="itin-around-title">Planned around</h2>
            <a class="itin-around__edit" href="{{ route('plan.edit') }}">Change</a>
        </header>
        <p class="itin-around__chips">
            @if ($stayLabel)<span class="itin-around__chip">{{ $stayLabel }}</span>@endif
            @if (isset($distanceNames[$preference->distance_pref]))
                <span class="itin-around__chip">{{ $distanceNames[$preference->distance_pref] }}, {{ $radius === null ? 'no distance limit' : 'within about '.(int) $radius.' km' }}</span>
            @endif
            @if (filled($transport))<span class="itin-around__chip">{{ TransportMode::label($transport) }}</span>@endif
        </p>
        <p class="itin-around__note"><x-icon name="info" /> Check opening hours before you go.</p>
    </section>
@endif
