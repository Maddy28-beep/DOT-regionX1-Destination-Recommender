@extends('layouts.app')

@section('title', $accommodation->name.' — ExploreDVO')

@section('content')
@php
    $gradients = [
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
    ];
    $gradient = $gradients[$accommodation->id % count($gradients)];
@endphp

<div class="container">
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('accommodations.index') }}">Accommodations</a> /
        {{ $accommodation->name }}
    </nav>

    @include('partials.gallery-hero', [
        'photos' => $accommodation->photos,
        'title' => $accommodation->name,
        'subtitle' => $accommodation->location.($accommodation->region ? ' · '.$accommodation->region->name : ''),
        'isAccredited' => $accommodation->is_accredited,
        'rating' => number_format($accommodation->rating, 1),
        'reviewCount' => $accommodation->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            <div class="fact-grid">
                <div class="fact">
                    <div class="fact-val">{{ $accommodation->price_per_night ? '₱'.number_format($accommodation->price_per_night) : '—' }}</div>
                    <div class="fact-label">Per Night</div>
                </div>
                <div class="fact">
                    <div class="fact-val">{{ $accommodation->check_in ?? '2:00 PM' }}</div>
                    <div class="fact-label">Check-in</div>
                </div>
                <div class="fact">
                    <div class="fact-val">{{ $accommodation->check_out ?? '12:00 PM' }}</div>
                    <div class="fact-label">Check-out</div>
                </div>
            </div>

            <div class="side-card">
                <h3 class="mt-0">About {{ $accommodation->name }}</h3>
                <p>{{ $accommodation->description ?? 'No description available yet for this accommodation.' }}</p>
                <div class="dest-tags" style="margin-top:14px;">
                    @if ($accommodation->type)<span class="dest-tag">{{ $accommodation->type }}</span>@endif
                    @if ($accommodation->dot_classification)<span class="dest-tag">{{ $accommodation->dot_classification }} Classification</span>@endif
                </div>
            </div>

            @if ($accommodation->roomTypes->count())
                <div class="side-card">
                    <h3 class="mt-0">Room Types</h3>
                    @foreach ($accommodation->roomTypes as $room)
                        <div class="review-item">
                            <div class="author">{{ $room->name }}</div>
                            <p class="comment">
                                @if ($room->price_min || $room->price_max)
                                    &#8369;{{ number_format($room->price_min ?? 0) }}&ndash;{{ number_format($room->price_max ?? 0) }} / night
                                @else
                                    Contact for rates
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="side-card">
                <h3 class="mt-0">Traveler Reviews ({{ $accommodation->reviews->count() }})</h3>
                @forelse ($accommodation->reviews as $review)
                    @include('partials.review-item')
                @empty
                    <p style="color:var(--muted);">No reviews yet.</p>
                @endforelse
            </div>
        </div>

        <div>
            <div class="side-card">
                <h3 class="mt-0">Plan your stay</h3>
                <p style="font-size:.85rem; color:var(--muted); margin-top:0;">
                    Budget tier: {{ $accommodation->price_tier ?? 'Not specified' }}<br>
                    Distance from city center: {{ $accommodation->distance_km ? number_format($accommodation->distance_km, 1).' km' : 'N/A' }}
                </p>
                <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($accommodation->name.' '.$accommodation->location) }}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Get Directions</a>
                @include('partials.check-in-button', ['type' => 'accommodations', 'listing' => $accommodation])
                <x-save-heart type="accommodations" :listing="$accommodation" variant="button" class="mt-10" />

                @include('partials.map-embed', ['latitude' => $accommodation->latitude, 'longitude' => $accommodation->longitude, 'name' => $accommodation->name])
            </div>
        </div>
    </div>

    @if ($nearby->count())
        <div class="section-tight">
            <div class="section-head">
                <div><h2>More in {{ $accommodation->region?->name ?? 'this area' }}</h2></div>
            </div>
            <div class="card-grid">
                @foreach ($nearby as $n)
                    @include('partials.listing-poster-card', ['listing' => $n])
                @endforeach
            </div>
        </div>
    @endif
</div>

<div class="sticky-cta">
    <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($accommodation->name.' '.$accommodation->location) }}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Get Directions</a>
    <x-save-heart type="accommodations" :listing="$accommodation" variant="button" class="cta-half" />
</div>
@endsection
