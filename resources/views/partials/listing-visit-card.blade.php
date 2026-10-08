{{--
    The "Plan your ..." card in the right column of a listing page: a few fact rows, the map and address,
    the directions button with a save heart beside it, the place's own links, and (optionally) a pointer
    to licensed guides.

    Each page decides which facts matter (a destination has a best time, a stay has check-in and
    check-out, a restaurant has hours); this partial only lays them out.

    $listing, $kicker, $title, $mapUrl (used by the default Get Directions button),
    $rows: list of ['icon', 'label', 'value', 'pill' => 'open'|'closed'|null, 'href' => link for the value],
    $type (URL segment for the save heart; leave out for listings that are not saveable),
    $primary (optional, replaces Get Directions with the page's own main action):
        ['label', 'icon', 'href' => url] or ['label', 'icon', 'post' => url], plus an optional 'hint',
    $guide (bool, default false)
--}}
@php
    // The address line: where it is, with the region added unless the location already names it.
    $address = trim((string) $listing->location);
    $regionName = $listing->region?->name;
    if ($address !== '' && $regionName && ! \Illuminate\Support\Str::contains($address, $regionName)) {
        $address .= ', '.$regionName;
    }
@endphp

<div class="visit-card">
    <div class="visit-card__kicker">{{ $kicker }}</div>
    <h3 class="visit-card__title">{{ $title }}</h3>

    @if ($rows !== [])
        <div class="visit-card__rows">
            @foreach ($rows as $row)
                <div class="visit-card__row">
                    <span class="visit-card__icon"><x-icon :name="$row['icon']" /></span>
                    <div class="visit-card__text">
                        <span class="visit-card__label">{{ $row['label'] }}</span>
                        <span class="visit-card__value">
                            @if (! empty($row['href']))<a href="{{ $row['href'] }}">{{ $row['value'] }}</a>@else{{ $row['value'] }}@endif
                        </span>
                    </div>
                    @if (! empty($row['pill']))
                        <span class="visit-card__pill visit-card__pill--{{ $row['pill'] }}">{{ $row['pill'] === 'open' ? 'Open now' : 'Closed now' }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($listing->latitude && $listing->longitude)
        <div class="visit-card__map">
            @include('partials.map-embed', ['latitude' => $listing->latitude, 'longitude' => $listing->longitude, 'name' => $listing->name])
        </div>
    @endif

    @if ($address !== '')
        <p class="visit-card__address"><x-icon name="map-pin" /> <span>{{ $address }}</span></p>
    @endif

    @if (! empty($primary))
        <div class="visit-card__actions">
            @if (! empty($primary['post']))
                <form method="POST" action="{{ $primary['post'] }}" class="visit-card__form">
                    @csrf
                    <button type="submit" class="visit-card__go"><x-icon :name="$primary['icon'] ?? 'arrow-right'" /> {{ $primary['label'] }}</button>
                </form>
            @else
                <a href="{{ $primary['href'] }}" class="visit-card__go"><x-icon :name="$primary['icon'] ?? 'arrow-right'" /> {{ $primary['label'] }}</a>
            @endif
        </div>
        @if (! empty($primary['hint']))
            <p class="visit-card__hint">{{ $primary['hint'] }}</p>
        @endif
    @else
        <div class="visit-card__actions">
            <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="visit-card__go"><x-icon name="send" /> Get Directions</a>
            @if (! empty($type))
                <x-save-heart :type="$type" :listing="$listing" variant="square" />
            @endif
        </div>
    @endif

    @include('partials.find-them-online', ['listing' => $listing])

    @if (! empty($guide))
        <a href="{{ route('tour-operators.index') }}" class="visit-card__guide">
            <span><small>Need a guide?</small><strong>Find DOT-accredited tour operators</strong></span>
            <x-icon name="arrow-right" />
        </a>
    @endif
</div>
