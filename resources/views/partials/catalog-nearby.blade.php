@php $nearby = \App\Support\NearbyPlaces::selection(request()); @endphp
<section class="catalog-nearby" data-catalog-nearby aria-labelledby="catalogNearbyTitle">
    <div class="catalog-nearby__head">
        <h3 id="catalogNearbyTitle"><x-icon name="map-pin" /> Nearby places</h3><small>Optional</small>
    </div>
    <button type="button" class="catalog-nearby__locate" data-nearby-locate><x-icon name="locate" /> Use my location</button>
    <label class="sr-only" for="nearby_origin">Nearby starting point</label>
    <select id="nearby_origin" name="nearby_origin" data-nearby-origin>
        <option value="">Choose a starting point</option>
        @if ($nearby && $nearby['origin'] === 'current')<option value="current" selected>Your current location</option>@endif
        @foreach (\App\Support\NearbyPlaces::POINTS as $key => $point)
            <option value="{{ $key }}" @selected(($nearby['origin'] ?? '') === $key)>{{ $point['label'] }}</option>
        @endforeach
    </select>
    <input type="hidden" name="nearby_lat" value="{{ ($nearby['origin'] ?? '') === 'current' ? $nearby['lat'] : '' }}" data-nearby-lat @disabled(($nearby['origin'] ?? '') !== 'current')>
    <input type="hidden" name="nearby_lng" value="{{ ($nearby['origin'] ?? '') === 'current' ? $nearby['lng'] : '' }}" data-nearby-lng @disabled(($nearby['origin'] ?? '') !== 'current')>
    <fieldset class="catalog-nearby__radii" data-nearby-radii>
        <legend class="sr-only">Distance from starting point</legend>
        @foreach (\App\Support\NearbyPlaces::RADII as $km)
            <label><input type="radio" name="nearby_km" value="{{ $km }}" @checked(($nearby['km'] ?? 10) === $km) @disabled(! $nearby) data-filter-count><span>{{ $km }} km</span></label>
        @endforeach
    </fieldset>
    <p class="catalog-nearby__hint">Straight-line distance · Mapped places only.</p>
    <p class="catalog-nearby__status" data-nearby-status role="status" aria-live="polite"></p>
    <button type="button" class="catalog-nearby__clear" data-nearby-clear @if (! $nearby) hidden @endif>Clear nearby</button>
</section>
