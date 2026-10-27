{{--
    One row of a day's timeline on the itinerary page: the time on the left, a dot on the rail, and then
    either a card (a stop, a meal, the night's stay), a slim line (arrival, departure) or a small
    connector (the journey between two places).

    A stop's card shows its photo (or the poster illustration when there is none), its name, what you do
    there, how long to plan for, and its opening hours when they are written as a plain daily schedule.
    Nothing here is made up: every line comes from the schedule row or the listing it points at.

    $item   an ItineraryItem (listing, photos and region already loaded)
    $swap   the swap data from the controller
    $preference  the preference the plan was built from (optional): names the way of travelling on each journey and
            whether a resort stay matches the setting asked for
    $stay   the overnight row that follows when this row is dinner at the place the traveller sleeps: the two are shown as
            one card, "Dinner & overnight stay" (optional)
    $ideas  optional souvenir ideas for this row (only Day 1's "Free time until dinner" has any); see FreeTimeIdeas.
            They are suggestions beside the schedule, never rows of it: no time, nothing booked.
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
    $saveSegment = $listing ? \App\Http\Controllers\SavedListingController::segmentFor($listing) : null;
    $merged = ($stay ?? null) !== null && $kind === 'meal';

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

    // How they said they would travel, shown on each journey. Nothing is shown when they did not say.
    $transport = $preference?->transport_pref ?? null;
    $transportIcon = match (true) {
        \App\Services\Recommendation\TransportMode::isTourOperator($transport) => 'van',
        $transport === \App\Services\Recommendation\TransportMode::JEEP => 'bus',
        $transport === \App\Services\Recommendation\TransportMode::RENTAL_CAR => 'key',
        default => 'car',
    };

    // A resort stay's settings, and whether they include the one the traveller asked for.
    $isStay = $kind === 'overnight' || $merged;
    $stayTags = $isStay && $listing instanceof \App\Models\Accommodation ? $listing->resortSettingList() : [];
    $askedSetting = ($preference?->accommodation_pref ?? null) === 'Resort' ? ($preference->resort_setting ?? null) : null;
    $stayMatches = $askedSetting && in_array($askedSetting, $stayTags, true);

    $icon = match ($kind) {
        'baseline', 'departure' => 'map-pin',
        'overnight' => 'moon',
        'meal' => 'utensils',
        'travel' => 'car',
        'free' => 'clock',
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
        <span class="tl-dot"></span>
    </div>

    <div class="tl-content">
        @if ($kind === 'travel')
            {{-- "15 min · 1.4 km": the way of travelling is the icon, the destination is the next card. --}}
            <p class="tl-travel">
                <x-icon :name="$transportIcon" />
                <span class="sr-only">{{ $item->title }}: </span>
                <span>{{ $item->travelBrief() }}</span>
            </p>
        @elseif ($isCard)
            <article class="tl-card tl-card--{{ $kind }}">
                <div class="tl-card__media">
                    @if ($realPhoto)
                        <img src="{{ $realPhoto->url() }}" alt="" loading="lazy" decoding="async">
                        @if ($realPhoto->isIllustration())
                            <span class="photo-illustration-tag photo-illustration-tag--card">Illustration</span>
                        @endif
                    @elseif ($kind === 'activity' && method_exists($listing, 'posterScene'))
                        @include('partials.poster-illustration', ['scene' => $listing->posterScene()])
                    @else
                        <span class="tl-card__icon"><x-icon :name="$icon" /></span>
                    @endif
                </div>

                <div class="tl-card__body">
                    @php
                        // The small line above the name: what kind of place a stop is, or which meal / night this is.
                        $label = match (true) {
                            $merged => 'Dinner & overnight stay',
                            $kind === 'overnight' => $item->title,
                            $kind === 'meal' => trim(\Illuminate\Support\Str::before($item->title, ' — ')),
                            $kind === 'activity' && $item->destination_id => $listing->type ?? null,
                            default => null,
                        };
                    @endphp
                    @if (filled($label))<p class="tl-card__label">{{ $label }}</p>@endif
                    @if ($kind === 'activity' && ! $item->destination_id)
                        {{-- The souvenir stop has no name in its title, so the shop is named as the subject. --}}
                        <h4>{{ $item->title }}</h4>
                        <p class="tl-card__what"><a href="{{ route($route, $listing) }}">{{ $listing->name }}</a></p>
                    @elseif ($kind === 'activity')
                        <h4><a href="{{ route($route, $listing) }}">{{ $listing->name }}</a></h4>
                        <p class="tl-card__what">{{ $what }}</p>
                    @elseif ($isStay && $route)
                        {{-- The night's stay is named, like every other stop, instead of only "Overnight stay". --}}
                        <h4><a href="{{ route($route, $listing) }}">{{ $listing->name }}</a></h4>
                        @if ($merged)
                            <p class="tl-card__what"><x-icon name="building" /> Dinner {{ $start?->format('g:i A') }} &middot; check in for the night</p>
                        @endif
                        @if ($stayTags !== [] || $stayMatches)
                            <p class="tl-card__tags">
                                @if ($stayMatches)<span class="tl-tag tl-tag--match"><x-icon name="check" /> Matches your choice</span>@endif
                                @foreach ($stayTags as $tag)<span class="tl-tag">{{ $tag === 'All-Inclusive' ? 'All Inclusive' : $tag }}</span>@endforeach
                            </p>
                        @endif
                    @elseif ($kind === 'meal' && $route && $listing)
                        <h4><a href="{{ route($route, $listing) }}">{{ $listing->name }}</a></h4>
                    @else
                        <h4>{{ $item->title }}</h4>
                        <p class="tl-card__what">{{ $item->travelSummary() }}</p>
                    @endif

                    @if ($item->durationShort() && in_array($kind, ['activity', 'meal'], true))
                        <p class="tl-card__meta"><x-icon name="timer" /> About {{ $item->durationShort() }}</p>
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
                            <button type="button" class="swap-toggle" data-swap-toggle aria-expanded="false" title="Swap this stop"><x-icon name="swap" /><span class="sr-only">Swap this stop</span></button>
                            <div class="swap-panel" data-swap-panel hidden></div>
                        </div>
                    @endif
                </div>

                @if ($saveSegment)
                    <div class="tl-card__actions"><x-save-heart :type="$saveSegment" :listing="$listing" variant="square" /></div>
                @endif
            </article>
        @else
            {{-- Arrival, departure, or a row whose place we do not hold: a slim line, not a card. --}}
            <p class="tl-line">
                <span class="tl-line__icon"><x-icon :name="$icon" /></span>
                <span>
                    @if ($kind === 'baseline' && $preference)
                        <strong>Start in {{ $preference->origin_label ?: 'Davao City centre' }}</strong>
                        <small>
                            @if (blank($preference->arrival_time))
                                No arrival time set, so we assumed {{ $start?->format('g:i A') }}. <a href="{{ route('plan.edit') }}">Set arrival time</a>
                            @else
                                You arrive at {{ $start?->format('g:i A') }}.
                            @endif
                        </small>
                    @elseif ($listing && $route && str_contains($item->title, $listing->name))
                        <a href="{{ route($route, $listing) }}">{{ $item->title }}</a>
                    @else
                        <strong>{{ $item->title }}</strong>
                    @endif
                    @if ($kind === 'free' && $item->note === 'Nothing is arranged for this time.')
                        {{-- The free time before dinner: said plainly, and where to spend it. --}}
                        <small>Nothing planned &mdash; rest at your {{ ($preference?->accommodation_pref ?? null) === 'Resort' ? 'resort' : 'stay' }} or explore nearby.</small>
                    @elseif ($kind !== 'baseline' || ! $preference)
                        <small>{{ $item->travelSummary() }}</small>
                        @if ($item->note)
                            <small>{{ $item->note }}</small>
                        @endif
                    @endif
                </span>
            </p>

            @if ($kind === 'free' && ! empty($ideas))
                <details class="tl-ideas">
                    <summary>Optional ideas for this free time ({{ count($ideas) }})</summary>
                    <p class="tl-ideas__intro">These are ideas only. They are not part of your plan, nothing is booked, and we can't promise they're open.</p>
                    <ul class="tl-ideas__list">
                        @foreach ($ideas as $idea)
                            <li>
                                <a href="{{ route('souvenir-centers.show', $idea['shop']) }}">{{ $idea['shop']->name }}</a>
                                <span>Souvenir shop &middot; Approx. {{ rtrim(rtrim(number_format($idea['km'], 1), '0'), '.') }} km from your accommodation</span>
                                <small>Opening hours are not listed. Check before visiting.</small>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @endif
    </div>
</div>
