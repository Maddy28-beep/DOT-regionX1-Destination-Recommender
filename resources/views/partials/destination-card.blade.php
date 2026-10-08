{{--
    The destination card: photo with the heart (and a NEW tag until it has reviews), then the DOT mark and
    rating, name, location, tags, and a footer with the entry fee and the distance from the city centre.

    Rendered by listing-poster-card for destinations only; the other listing types keep the poster card.
    The heart sits outside the anchor (a form inside a link is invalid and would swallow the click), so the
    wrapper is what lifts on hover, exactly as in the poster card.

    $listing (a Destination)
--}}
@php
    $coverPhoto = $listing->coverPhoto();
    $closedBadge = method_exists($listing, 'operatingBadge') ? $listing->operatingBadge() : null;
    $tags = array_slice($listing->posterTags(), 0, 2);
    $tier = $listing->posterTier();

    // "150 / person" -> bold "150", muted "/ person"
    $priceAmount = $listing->posterPriceAmount();
    [$priceMain, $priceUnit] = $priceAmount ? array_pad(explode(' / ', $priceAmount, 2), 2, null) : [null, null];

    // Where it is, with the region added only when the location does not already name it.
    $where = trim((string) $listing->location);
    $regionName = $listing->region?->name;
    if ($regionName && ! \Illuminate\Support\Str::contains($where, $regionName)) {
        $where = trim($where.', '.$regionName, ', ');
    }

    $distance = $listing->distance_km
        ? rtrim(rtrim(number_format((float) $listing->distance_km, 1), '0'), '.').' km from city'
        : null;
@endphp

<div class="dpost-card-wrap">
    <x-save-heart type="destinations" :listing="$listing" />
    <a href="{{ $listing->posterUrl() }}" class="dpost-card">
        <div class="dpost-card__art dcard__art has-save {{ $closedBadge ? 'is-closed' : '' }}" @if ($closedBadge) data-closed="{{ $closedBadge }}" @endif>
            @if ($coverPhoto)
                {{-- alt is empty on purpose: the name below already names the place and the card is one link. --}}
                <img src="{{ $coverPhoto->url() }}" alt="" class="dpost-card__photo" loading="lazy" decoding="async">
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
            <div class="dcard__loc"><x-icon name="map-pin" /> <span>{{ $where }}</span></div>

            @if ($tags)
                <div class="dpost-tags dcard__tags">
                    @foreach ($tags as $tag)
                        <span class="dpost-tag">{{ $tag }}</span>
                    @endforeach
                </div>
            @endif

            <div class="dcard__foot">
                <span class="dcard__price">
                    @if ($priceMain)
                        <strong><span class="currency">&#8369;</span>{{ $priceMain }}</strong>@if ($priceUnit) <small>/ {{ $priceUnit }}</small>@endif
                    @elseif ($tier === 0)
                        <strong>Free entry</strong>
                    @elseif ($tier !== null)
                        {{-- No exact fee on record: show the price band instead. --}}
                        <span class="dcard__band" aria-label="Price tier {{ $tier }} of 3">
                            @for ($i = 1; $i <= 3; $i++)<span class="{{ $i <= $tier ? 'is-on' : '' }}">&#8369;</span>@endfor
                        </span>
                    @endif
                </span>

                @if ($distance)
                    <span class="dcard__dist"><x-icon name="swap" /> {{ $distance }}</span>
                @endif
            </div>
        </div>
    </a>
</div>
