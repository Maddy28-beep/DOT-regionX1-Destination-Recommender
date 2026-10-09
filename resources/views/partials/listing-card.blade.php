{{--
    The listing card for destinations, stays, packages, souvenir centers and tour operators: photo with the
    heart (and a NEW tag until the listing has reviews), then the DOT mark and rating, name, a sub-line,
    tags, and a footer whose content depends on the type -- entry fee and distance for a destination,
    "from" price per night for a stay, price and duration for a package, the number of packages for a tour
    operator. Whatever a listing has no data for is left out rather than filled in.

    Rendered by listing-poster-card. The heart sits outside the anchor (a form inside a link is invalid and
    would swallow the click), so the wrapper is what lifts on hover. Only listings that can be saved get
    one (see SavedListingController::segmentFor); packages and tour operators are booked, not saved.

    $listing
--}}
@php
    $coverPhoto = $listing->coverPhoto();
    $closedBadge = method_exists($listing, 'operatingBadge') ? $listing->operatingBadge() : null;
    $saveSegment = \App\Http\Controllers\SavedListingController::segmentFor($listing);
    $tier = $listing->posterTier();

    $isDestination = $listing instanceof \App\Models\Destination;
    $isStay = $listing instanceof \App\Models\Accommodation;
    $isPackage = $listing instanceof \App\Models\Package;
    $isOperator = $listing instanceof \App\Models\TourOperator;

    // Where it is, with the region added only when the location does not already name it.
    $where = trim((string) $listing->location);
    $regionName = $listing->region?->name;
    if ($regionName && ! \Illuminate\Support\Str::contains($where, $regionName)) {
        $where = trim($where.', '.$regionName, ', ');
    }

    // The line under the name: a package leads with who runs it, everything else with where it is.
    $subIcon = 'map-pin';
    $sub = $where;
    if ($isPackage) {
        $provider = $listing->provider_name ?: ($listing->relationLoaded('tourOperator') ? $listing->tourOperator?->name : null);
        if ($provider) {
            $subIcon = 'user';
            $sub = 'by '.$provider;
        }
    }

    // A package's provider is already in the sub-line, so only its type is a chip.
    $tags = $isPackage ? array_filter([$listing->type]) : $listing->posterTags();
    $tags = array_slice(array_values($tags), 0, 2);

    // Price: bold amount, with a muted unit after it (and "from" before it for a stay).
    $priceLead = null;
    [$priceMain, $priceUnit] = [null, null];
    if ($isDestination) {
        $amount = $listing->posterPriceAmount();   // "150 / person", "150–450 / person", "50 max / person"
        [$priceMain, $priceUnit] = $amount ? array_pad(explode(' / ', $amount, 2), 2, null) : [null, null];
    } elseif ($isStay && $listing->price_per_night) {
        [$priceLead, $priceMain, $priceUnit] = ['from', number_format($listing->price_per_night), 'night'];
    } elseif ($isPackage && $listing->price_per_pax) {
        [$priceMain, $priceUnit] = [number_format($listing->price_per_pax), 'person'];
    }

    // Right side of the footer.
    $meta = null;
    if ($isDestination && $listing->distance_km) {
        $meta = ['swap', rtrim(rtrim(number_format((float) $listing->distance_km, 1), '0'), '.').' km', ' from city'];
    } elseif ($isPackage && $listing->duration_label) {
        $meta = ['clock', $listing->duration_label, null];
    }

    // Tour operators are summed up by how many packages they run (loaded with the grid, never queried per card).
    $packageCount = $isOperator ? ($listing->packages_count ?? null) : null;

    // A tour operator with no photo gets its initials on a plain tile rather than a generic illustration.
    $initials = $isOperator
        ? \Illuminate\Support\Str::of($listing->name)->explode(' ')->filter(fn ($w) => preg_match('/^\p{L}/u', $w))
            ->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('')
        : null;

    $hasFooter = $priceMain || ($isDestination && $tier === 0) || $packageCount || ($tier !== null && ! $isOperator) || $meta;
@endphp

<div class="dpost-card-wrap">
    @if ($saveSegment)
        <x-save-heart :type="$saveSegment" :listing="$listing" />
    @endif
    <a href="{{ $listing->posterUrl() }}" class="dpost-card">
        <div class="dpost-card__art dcard__art {{ $saveSegment ? 'has-save' : '' }} {{ $closedBadge ? 'is-closed' : '' }}" @if ($closedBadge) data-closed="{{ $closedBadge }}" @endif>
            @if ($coverPhoto)
                {{-- alt is empty on purpose: the name below already names the place and the card is one link. --}}
                <img src="{{ $coverPhoto->url() }}" alt="" class="dpost-card__photo" loading="lazy" decoding="async">
            @elseif ($isOperator && $initials)
                <div class="dcard__monogram"><span>{{ $initials }}</span></div>
            @else
                @include('partials.poster-illustration', ['scene' => $listing->posterScene()])
            @endif

            @if ($listing->review_count < 1)
                <span class="dcard__new">New</span>
            @endif
        </div>

        <div class="dcard__body">
            <div class="dcard__top">
                @if ($listing->is_accredited)
                    <span class="dcard__dot"><x-icon name="shield-check" /> DOT accredited</span>
                @else
                    <span></span>
                @endif

                @if ($listing->review_count > 0)
                    <span class="dcard__rating">
                        <x-icon name="star" />
                        <strong>{{ number_format($listing->rating, 1) }}</strong>
                        <span>({{ $listing->review_count }})</span>
                    </span>
                @endif
            </div>

            <h3 class="dcard__name">{{ $listing->name }}</h3>
            @if ($sub !== '')
                <div class="dcard__loc"><x-icon :name="$subIcon" /> <span title="{{ $sub }}">{{ $sub }}</span></div>
            @endif

            @if ($tags)
                <div class="dpost-tags dcard__tags">
                    @foreach ($tags as $tag)
                        <span class="dpost-tag">{{ $tag }}</span>
                    @endforeach
                </div>
            @endif

            @if ($hasFooter)
                <div class="dcard__foot">
                    <span class="dcard__price">
                        @if ($priceMain)
                            @if ($priceLead)<small class="dcard__from">{{ $priceLead }}</small> @endif<strong><span class="currency">&#8369;</span>{{ $priceMain }}</strong>@if ($priceUnit) <small>/ {{ $priceUnit }}</small>@endif
                        @elseif ($isDestination && $tier === 0)
                            <strong>Free entry</strong>
                        @elseif ($packageCount)
                            <span class="dcard__count">{{ $packageCount }} tour {{ \Illuminate\Support\Str::plural('package', $packageCount) }}</span>
                        @elseif ($tier !== null && ! $isOperator)
                            {{-- No exact price on record: show the price band instead. --}}
                            <span class="dcard__band" aria-label="Price tier {{ $tier }} of 3">
                                @for ($i = 1; $i <= 3; $i++)<span class="{{ $i <= $tier ? 'is-on' : '' }}">&#8369;</span>@endfor
                            </span>
                        @endif
                    </span>

                    @if ($meta)
                        <span class="dcard__dist"><x-icon :name="$meta[0]" /> {{ $meta[1] }}@if ($meta[2])<span class="dcard__dist-more">{{ $meta[2] }}</span>@endif</span>
                    @endif
                </div>
            @endif
        </div>
    </a>
</div>
