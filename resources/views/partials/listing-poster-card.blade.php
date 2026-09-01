@props(['listing'])

{{--
    The one card used by every listing grid -- Destinations, Accommodations,
    Restaurants, Souvenir Centres, Tour Operators and Packages, on both the
    index pages and the "related" strips at the bottom of detail pages.

    It reads nothing type-specific: everything it needs comes from
    PresentsAsPosterCard, so adding a listing type means implementing that
    trait rather than forking this file. It replaced six near-identical
    partials that each rendered a flat gradient rectangle where the artwork
    should have been.
--}}

@php
    $tier = $listing->posterTier();
    $priceAmount = $listing->posterPriceAmount();
    $tags = $listing->posterTags();

    // Places can be hearted; packages and tour operators can't (see
    // SavedListingController::segmentFor). The heart has to live outside the
    // card's anchor -- a form nested in a link is invalid markup and the
    // button would swallow the click -- so the wrapper is what lifts on hover.
    $saveSegment = \App\Http\Controllers\SavedListingController::segmentFor($listing);
@endphp

<div class="dpost-card-wrap">
@if ($saveSegment)
    <x-save-heart :type="$saveSegment" :listing="$listing" />
@endif
<a href="{{ $listing->posterUrl() }}" class="dpost-card">
    <div class="dpost-card__art {{ $saveSegment ? 'has-save' : '' }}">
        @include('partials.poster-illustration', ['scene' => $listing->posterScene()])
        <div class="halftone"></div>
        <div class="dpost-card__scrim"></div>

        @if ($listing->is_accredited)
            <span class="dpost-badge-accredited" title="DOT Accredited">
                <svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="10" cy="10" r="8.5" fill="none" stroke="currentColor" stroke-width="1.4"/>
                    <circle cx="10" cy="10" r="3.4" fill="currentColor"/>
                </svg>
                DOT Accredited
            </span>
        @endif

        <span class="dpost-badge-rating">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="currentColor" d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
            @if ($listing->review_count > 0) {{ number_format($listing->rating, 1) }} @else New @endif
        </span>

        <span class="dpost-card__name">{{ $listing->name }}</span>
    </div>

    <div class="dpost-card__body">
        <div class="dpost-loc">{{ $listing->posterMeta() }}</div>

        @if ($tags)
            <div class="dpost-tags">
                @foreach (array_slice($tags, 0, 2) as $tag)
                    <span class="dpost-tag">{{ $tag }}</span>
                @endforeach
            </div>
        @endif

        @if ($tier !== null || $priceAmount)
            <div class="dpost-price">
                @if ($tier === 0)
                    <span class="dpost-price__free">Free entry</span>
                @elseif ($tier !== null)
                    @for ($i = 1; $i <= 3; $i++)
                        <span class="{{ $i <= $tier ? 'is-active' : '' }}">&#8369;</span>
                    @endfor
                @endif

                @if ($priceAmount)
                    <span class="dpost-price__amount"><span class="currency">&#8369;</span>{{ $priceAmount }}</span>
                @endif
            </div>
        @endif
    </div>
</a>
</div>
