@extends('layouts.app')

@section('title', $restaurant->name.' — ExploreDVO')

@section('content')
@php
    $gradients = [
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#ff6b35,#e2551f)',
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
    ];
    $gradient = $gradients[$restaurant->id % count($gradients)];
    $mapUrl = $restaurant->latitude && $restaurant->longitude
        ? "https://www.google.com/maps/search/?api=1&query={$restaurant->latitude},{$restaurant->longitude}"
        : 'https://www.google.com/maps/search/?api=1&query='.urlencode($restaurant->name.' '.$restaurant->location);
@endphp

<div class="container">
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('restaurants.index') }}">Restaurants</a> /
        {{ $restaurant->name }}
    </nav>

    @include('partials.gallery-hero', [
        'photos' => $restaurant->photos,
        'title' => $restaurant->name,
        'subtitle' => $restaurant->location.($restaurant->region ? ' · '.$restaurant->region->name : ''),
        'isAccredited' => $restaurant->is_accredited,
        'rating' => number_format($restaurant->rating, 1),
        'reviewCount' => $restaurant->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            <div class="fact-grid">
                <div class="fact">
                    <div class="fact-val">{{ $restaurant->price_tier ?? '—' }}</div>
                    <div class="fact-label">Budget Tier</div>
                </div>
                <div class="fact">
                    <div class="fact-val">{{ $restaurant->cuisine_type ?? '—' }}</div>
                    <div class="fact-label">Cuisine</div>
                </div>
                <div class="fact">
                    <div class="fact-val">{{ $restaurant->opening_hours ?? '—' }}</div>
                    <div class="fact-label">Hours</div>
                </div>
            </div>

            <div class="side-card">
                <h3 class="mt-0">About {{ $restaurant->name }}</h3>
                <p>{{ $restaurant->description ?? 'No description available yet for this restaurant.' }}</p>
            </div>

            <div class="side-card">
                <h3 class="mt-0">Traveler Reviews ({{ $restaurant->reviews->count() }})</h3>
                @forelse ($restaurant->reviews as $review)
                    @include('partials.review-item')
                @empty
                    <p style="color:var(--muted);">No reviews yet. Be the first to dine and share your experience.</p>
                @endforelse
            </div>
        </div>

        <div>
            <div class="side-card">
                <h3 class="mt-0">Plan your visit</h3>
                <p style="font-size:.85rem; color:var(--muted); margin-top:0;">
                    Hours: {{ $restaurant->opening_hours ?? 'Contact establishment' }}<br>
                    Contact: {{ $restaurant->contact_number ?? 'Not provided' }}
                </p>
                <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Get Directions</a>
                @include('partials.check-in-button', ['type' => 'restaurants', 'listing' => $restaurant])

                @include('partials.map-embed', ['latitude' => $restaurant->latitude, 'longitude' => $restaurant->longitude, 'name' => $restaurant->name])
            </div>
        </div>
    </div>

    @if ($nearby->count())
        <div class="section-tight">
            <div class="section-head">
                <div><h2>More in {{ $restaurant->region?->name ?? 'this area' }}</h2></div>
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
    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Get Directions</a>
</div>
@endsection
