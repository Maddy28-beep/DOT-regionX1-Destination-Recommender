{{--
    One row of a day's timeline on the itinerary page: the time on the left, a dot on the rail, and then
    either a card (a stop, a meal, the night's stay), a slim line (arrival, departure) or a small
    connector (the journey between two places).

    A stop's card shows its photo (or the poster illustration when there is none), its name, what you do
    there, how long to plan for, and its opening hours when they are written as a plain daily schedule.
    Nothing here is made up: every line comes from the schedule row or the listing it points at.

    $item   an ItineraryItem (listing, photos and region already loaded)
    $swap   the swap data from the controller
--}}
@php
    $listing = $item->listing();
    $route = match (true) {
        (bool) $item->destination_id => 'destinations.show',
        (bool) $item->accommodation_id => 'accommodations.show',
        (bool) $item->restaurant_id => 'restaurants.show',
        (bool) $item->souvenir_center_id => 'souvenir-centers.show',
        default => null,
    };

    $kind = $item->kind;
    $start = filled($item->starts_at) ? \Illuminate\Support\Carbon::parse($item->starts_at) : null;
    $end = filled($item->ends_at) ? \Illuminate\Support\Carbon::parse($item->ends_at) : null;

    $isCard = $listing && in_array($kind, ['activity', 'meal', 'overnight'], true);

    // The photo, unless it is only the seeded placeholder drawing.
    $photo = $listing?->coverPhoto();
    $realPhoto = $photo && ! str_ends_with(strtolower($photo->path), '.svg') ? $photo : null;

    // "Nature walks and leisure activities — Malagos Garden Resort": the name is the card's title, the rest is what you do.
    $what = $item->title;
    if ($listing && str_contains($what, ' — '.$listing->name)) {
        $what = trim(str_replace(' — '.$listing->name, '', $what));
    }

    $hours = $listing && filled($listing->hours ?? null) && \App\Support\OpeningHours::windows($listing->hours) !== null
        ? $listing->hours
        : null;

    $icon = match ($kind) {
        'baseline', 'departure' => 'map-pin',
        'overnight' => 'moon',
        'meal' => 'utensils',
        'travel' => 'car',
        default => 'compass',
    };
@endphp

<div class="tl-row tl-row--{{ $kind }}">
    <div class="tl-time">
        @if ($kind !== 'travel' && $start)
            <strong>{{ $start->format('g:i A') }}</strong>
            @if ($end)<span>to {{ $end->format('g:i A') }}</span>@endif
        @endif
    </div>

    <div class="tl-rail" aria-hidden="true">
        <span class="tl-dot">@if ($kind === 'travel')<x-icon name="car" />@endif</span>
    </div>

    <div class="tl-content">
        @if ($kind === 'travel')
            <p class="tl-travel">
                <strong>{{ $item->title }}</strong>
                <span>{{ $item->travelSummary() }}</span>
            </p>
        @elseif ($isCard)
            <article class="tl-card tl-card--{{ $kind }}">
                <div class="tl-card__media">
                    @if ($realPhoto)
                        <img src="{{ $realPhoto->url() }}" alt="" loading="lazy" decoding="async">
                    @elseif ($kind === 'activity' && method_exists($listing, 'posterScene'))
                        @include('partials.poster-illustration', ['scene' => $listing->posterScene()])
                    @else
                        <span class="tl-card__icon"><x-icon :name="$icon" /></span>
                    @endif
                </div>

                <div class="tl-card__body">
                    @if ($kind === 'activity' && ! $item->destination_id)
                        {{-- The souvenir stop has no name in its title, so the shop is named as the subject. --}}
                        <h4>{{ $item->title }}</h4>
                        <p class="tl-card__what"><a href="{{ route($route, $listing) }}">{{ $listing->name }}</a></p>
                    @elseif ($kind === 'activity')
                        <h4><a href="{{ route($route, $listing) }}">{{ $listing->name }}</a></h4>
                        <p class="tl-card__what">{{ $what }}</p>
                    @else
                        <h4>{{ $item->title }}</h4>
                        <p class="tl-card__what">{{ $item->travelSummary() }}</p>
                    @endif

                    @if ($item->durationLabel() && $kind === 'activity')
                        <p class="tl-card__meta"><x-icon name="timer" /> Suggested visit: {{ $item->durationLabel() }}</p>
                    @endif
                    @if ($hours && $kind === 'activity')
                        <p class="tl-card__meta"><x-icon name="clock" /> Open {{ $hours }}</p>
                    @endif

                    @if ($item->note)
                        <p class="tl-card__note">{{ $item->note }}</p>
                    @endif

                    @if ($item->ruleExplanation())
                        <div class="tl-card__pairing">
                            <span class="pairing-tag">Popular pairing with {{ $item->rule_basis }}</span>
                            <details class="pairing-why">
                                <summary>why this pick?</summary>
                                <p>{{ $item->ruleExplanation() }}</p>
                            </details>
                        </div>
                    @endif

                    @if (($swap['available'] ?? false) && $kind === 'activity' && $item->destination_id)
                        @php $closed = $swap['closed'][$item->destination_id] ?? null; @endphp
                        <div class="swap" data-swap
                             data-destination="{{ $item->destination_id }}"
                             data-url="{{ route('plan.alternatives', $item->destination_id) }}"
                             data-swap-url="{{ route('plan.swap') }}"
                             data-token="{{ csrf_token() }}">
                            @if ($closed)
                                <div class="swap-notice" role="alert">
                                    <strong>Not available on your dates:</strong> {{ $closed['advisory'] }}.
                                    @if ($closed['substitute'])
                                        Closest open substitute:
                                        <strong>{{ $closed['substitute']['name'] }}</strong>
                                        ({{ $closed['substitute']['similarity'] }}% similar{{ $closed['substitute']['distance_km'] !== null ? ', '.$closed['substitute']['distance_km'].' km away' : '' }}).
                                        <form method="POST" action="{{ route('plan.swap') }}" class="swap-inline">
                                            @csrf
                                            <input type="hidden" name="original_id" value="{{ $item->destination_id }}">
                                            <input type="hidden" name="replacement_id" value="{{ $closed['substitute']['id'] }}">
                                            <button type="submit" class="btn btn-primary swap-use">Use {{ $closed['substitute']['name'] }} instead</button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                            <button type="button" class="swap-toggle" data-swap-toggle aria-expanded="false">Swap this stop</button>
                            <div class="swap-panel" data-swap-panel hidden></div>
                        </div>
                    @endif
                </div>
            </article>
        @else
            {{-- Arrival, departure, or a row whose place we do not hold: a slim line, not a card. --}}
            <p class="tl-line">
                <span class="tl-line__icon"><x-icon :name="$icon" /></span>
                <span>
                    @if ($listing && $route && str_contains($item->title, $listing->name))
                        <a href="{{ route($route, $listing) }}">{{ $item->title }}</a>
                    @else
                        <strong>{{ $item->title }}</strong>
                    @endif
                    <small>{{ $item->travelSummary() }}</small>
                    @if ($item->note)
                        <small>{{ $item->note }}</small>
                    @endif
                </span>
            </p>
        @endif
    </div>
</div>
