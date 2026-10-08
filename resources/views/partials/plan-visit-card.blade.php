{{--
    The destination page's "Plan your visit" card: best time, opening hours with an open/closed pill,
    how long to plan, the directions button with a save heart beside it, the place's own links, and a
    pointer to licensed guides.

    The pill appears only when the hours are a plain daily schedule we can read (see OpeningHours); for
    anything else ("Mon-Sat ...", "By arrangement") the text is shown without claiming open or closed.
    A place marked closed in the admin always reads Closed. The map and address sit between the facts
    and the directions button; a place with no coordinates simply has no map.

    $destination, $mapUrl
--}}
@php
    $hoursText = $destination->hours ?: null;
    $closedByStatus = $destination->isClosedByStatus(now());
    $openNow = \App\Support\OpeningHours::isOpenNow($hoursText);
    $pill = $closedByStatus ? 'closed' : ($openNow === true ? 'open' : ($openNow === false ? 'closed' : null));
    $spend = \App\Support\OpeningHours::spendLabel($destination->visit_duration) ?? 'Half day';

    // The address line: where it is, with the region added unless the location already names it.
    $address = trim((string) $destination->location);
    $regionName = $destination->region?->name;
    if ($address !== '' && $regionName && ! \Illuminate\Support\Str::contains($address, $regionName)) {
        $address .= ', '.$regionName;
    }
@endphp

<div class="visit-card">
    <div class="visit-card__kicker">Before you go</div>
    <h3 class="visit-card__title">Plan your visit</h3>

    <div class="visit-card__rows">
        <div class="visit-card__row">
            <span class="visit-card__icon"><x-icon name="sun" /></span>
            <div class="visit-card__text">
                <span class="visit-card__label">Best time</span>
                <span class="visit-card__value">{{ $destination->best_time ?: 'Year-round' }}</span>
            </div>
        </div>

        <div class="visit-card__row">
            <span class="visit-card__icon"><x-icon name="clock" /></span>
            <div class="visit-card__text">
                <span class="visit-card__label">Hours</span>
                <span class="visit-card__value">{{ $hoursText ?? 'Contact establishment' }}</span>
            </div>
            @if ($pill)
                <span class="visit-card__pill visit-card__pill--{{ $pill }}">{{ $pill === 'open' ? 'Open now' : 'Closed now' }}</span>
            @endif
        </div>

        <div class="visit-card__row">
            <span class="visit-card__icon"><x-icon name="timer" /></span>
            <div class="visit-card__text">
                <span class="visit-card__label">Plan to spend</span>
                <span class="visit-card__value">{{ $spend }}</span>
            </div>
        </div>
    </div>

    @if ($destination->latitude && $destination->longitude)
        <div class="visit-card__map">
            @include('partials.map-embed', ['latitude' => $destination->latitude, 'longitude' => $destination->longitude, 'name' => $destination->name])
        </div>
    @endif

    @if ($address !== '')
        <p class="visit-card__address"><x-icon name="map-pin" /> <span>{{ $address }}</span></p>
    @endif

    <div class="visit-card__actions">
        <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="visit-card__go"><x-icon name="send" /> Get Directions</a>
        <x-save-heart type="destinations" :listing="$destination" variant="square" />
    </div>

    @include('partials.find-them-online', ['listing' => $destination])

    <a href="{{ route('tour-operators.index') }}" class="visit-card__guide">
        <span><small>Need a guide?</small><strong>Find DOT-accredited tour operators</strong></span>
        <x-icon name="arrow-right" />
    </a>
</div>
