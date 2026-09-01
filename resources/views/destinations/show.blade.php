@extends('layouts.app')

@section('title', $destination->name.' — ExploreDVO')

@section('content')
@php
    $mapUrl = $destination->latitude && $destination->longitude
        ? "https://www.google.com/maps/search/?api=1&query={$destination->latitude},{$destination->longitude}"
        : 'https://www.google.com/maps/search/?api=1&query='.urlencode($destination->name.' '.$destination->location);
@endphp

<div class="container">
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('destinations.index') }}">Destinations</a> /
        {{ $destination->name }}
    </nav>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    {{--
        Always the illustrated poster hero here, matching Popular Destinations
        and Featured Packages -- not conditional on whether photos exist.
        The seeded "photos" on a couple of destinations (Samal Island, Eden
        Nature Park) are auto-generated placeholder gradients with a text
        label baked in (see ListingPhotoSeeder), not real photography, so
        treating them as a real-photo gallery misrepresented them as content
        and broke the one-visual-language goal. Real uploaded photos, once
        establishments add them, still belong in a proper gallery -- just
        not as this hero.
    --}}
    @include('partials.dest-detail-hero', ['destination' => $destination])

    <div class="detail-layout">
        <div>
            <div class="fact-grid">
                <div class="fact">
                    <div class="fact-val">{{ str_replace('-Friendly', '', $destination->price_tier ?? '—') }}</div>
                    <div class="fact-label">Tier</div>
                </div>
                <div class="fact">
                    <div class="fact-val">
                        @if (($destination->entry_fee_min ?? 0) == 0 && ($destination->entry_fee_max ?? 0) == 0)
                            Free
                        @elseif (($destination->entry_fee_min ?? 0) == ($destination->entry_fee_max ?? 0))
                            <span class="currency">&#8369;</span>{{ number_format($destination->entry_fee_min ?? 0) }}
                        @else
                            <span class="currency">&#8369;</span>{{ number_format($destination->entry_fee_min ?? 0) }}&ndash;{{ number_format($destination->entry_fee_max ?? 0) }}
                        @endif
                    </div>
                    <div class="fact-label">Entry Fee</div>
                </div>
                <div class="fact">
                    <div class="fact-val">{{ $destination->distance_km ? number_format($destination->distance_km, 1).' km' : '—' }}</div>
                    <div class="fact-label">From City Center</div>
                </div>
            </div>

            <div class="side-card">
                <h3 class="mt-0">About {{ $destination->name }}</h3>
                <p>{{ $destination->description ?? 'No description available yet for this destination.' }}</p>

                @if ($destination->tags->count())
                    @php
                        /*
                         * Amenity chips open a viewer showing what that facility looks
                         * like. Amenities come from a closed vocabulary (see
                         * RecommendationDataSeeder), so each one has a matching
                         * poster-style plate in public/img/amenities -- with default.svg
                         * covering any value added later that has no plate yet.
                         *
                         * Category chips ("Wildlife", "Cultural Heritage") are open-ended
                         * descriptors with no such artwork, so they stay non-interactive
                         * rather than all opening the same generic image.
                         */
                        $tagViewer = [];
                        $tagChips = [];

                        foreach ($destination->tags as $tag) {
                            $plate = null;

                            if ($tag->kind === 'amenity') {
                                $slug = \Illuminate\Support\Str::slug($tag->value);
                                if (! file_exists(public_path("img/amenities/{$slug}.svg"))) {
                                    $slug = 'default';
                                }
                                $plate = asset("img/amenities/{$slug}.svg");
                            }

                            if ($plate) {
                                $tagViewer[] = ['url' => $plate, 'category' => $tag->value];
                                $tagChips[] = ['label' => $tag->value, 'open' => count($tagViewer) - 1];
                            } else {
                                $tagChips[] = ['label' => $tag->value, 'open' => null];
                            }
                        }
                    @endphp

                    <div style="margin-top:14px;" @if ($tagViewer) data-gallery data-photos='@json($tagViewer)' @endif>
                        <div class="dest-tags">
                            @foreach ($tagChips as $chip)
                                @if ($chip['open'] !== null)
                                    <button type="button" class="dest-tag dest-tag--viewable"
                                            data-open="{{ $chip['open'] }}"
                                            title="See what {{ $chip['label'] }} looks like here">
                                        {{ $chip['label'] }}
                                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                            <circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2.4"/>
                                            <line x1="15.6" y1="15.6" x2="21" y2="21" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
                                        </svg>
                                    </button>
                                @else
                                    <span class="dest-tag">{{ $chip['label'] }}</span>
                                @endif
                            @endforeach
                        </div>

                        @if ($tagViewer)
                            <div class="lightbox" data-lightbox-el>
                                <button type="button" class="lb-close" data-close aria-label="Close">&times;</button>
                                <button type="button" class="lb-prev" data-prev aria-label="Previous"><x-icon name="chevron-left" /></button>
                                <img class="lb-img" src="" alt="">
                                <button type="button" class="lb-next" data-next aria-label="Next"><x-icon name="chevron-right" /></button>
                                <div class="lb-meta"></div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="side-card">
                <h3 class="mt-0">Traveler Reviews ({{ $destination->reviews->count() }})</h3>
                @forelse ($destination->reviews as $review)
                    @include('partials.review-item')
                @empty
                    <p style="color:var(--muted);">No reviews yet. Be the first to visit and share your experience.</p>
                @endforelse
            </div>
        </div>

        <div>
            <div class="side-card">
                <h3 class="mt-0">Plan your visit</h3>
                <p class="visit-meta">
                    <strong>Best time to visit:</strong> {{ $destination->best_time ?? 'Year-round' }}<br>
                    <strong>Hours:</strong> {{ $destination->hours ?? 'Contact establishment' }}<br>
                    <strong>Suggested duration:</strong> {{ $destination->visit_duration ?? 'Half day' }}
                </p>

                <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-poster-primary btn-block">Get Directions</a>
                @include('partials.check-in-button', ['type' => 'destinations', 'listing' => $destination])

                <x-save-heart type="destinations" :listing="$destination" variant="button" class="mt-10" />

                @include('partials.map-embed', ['latitude' => $destination->latitude, 'longitude' => $destination->longitude, 'name' => $destination->name])
            </div>
        </div>
    </div>

    @if ($nearby->count())
        <div class="section-tight">
            <div class="section-head">
                <div>
                    <h2 class="poster-title" style="color:var(--ocean-teal-dark);">
                        @if ($nearbyIsSameRegion)
                            More in {{ $destination->region?->name ?? 'this area' }}
                        @else
                            You Might Also Like
                        @endif
                    </h2>
                </div>
            </div>
            <div class="dpost-grid">
                @foreach ($nearby as $n)
                    @include('partials.listing-poster-card', ['listing' => $n])
                @endforeach
            </div>
        </div>
    @endif
</div>

<div class="sticky-cta">
    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-poster-primary" style="flex:1;">Directions</a>
    <x-save-heart type="destinations" :listing="$destination" variant="button" class="cta-half" />
</div>
@endsection
