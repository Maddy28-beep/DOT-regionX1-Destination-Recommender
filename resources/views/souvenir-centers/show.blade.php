@extends('layouts.app')

@section('title', $souvenirCenter->name.' — ExploreDVO')

@section('content')
@php
    $gradients = [
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
    ];
    $gradient = $gradients[$souvenirCenter->id % count($gradients)];
    $mapUrl = 'https://www.google.com/maps/search/?api=1&query='.urlencode($souvenirCenter->name.' '.$souvenirCenter->location);
@endphp

<div class="container">
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('souvenir-centers.index') }}">Souvenir Centers</a> /
        {{ $souvenirCenter->name }}
    </nav>

    @include('partials.gallery-hero', [
        'photos' => $souvenirCenter->photos,
        'title' => $souvenirCenter->name,
        'subtitle' => $souvenirCenter->location.($souvenirCenter->region ? ' · '.$souvenirCenter->region->name : ''),
        'isAccredited' => $souvenirCenter->is_accredited,
        'rating' => number_format($souvenirCenter->rating, 1),
        'reviewCount' => $souvenirCenter->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            <div class="side-card">
                <h3 class="mt-0">About {{ $souvenirCenter->name }}</h3>
                <p>{{ $souvenirCenter->description ?? 'No description available yet for this souvenir center.' }}</p>
            </div>

            <div class="side-card">
                <h3 class="mt-0">Traveler Reviews ({{ $souvenirCenter->reviews->count() }})</h3>
                @forelse ($souvenirCenter->reviews as $review)
                    @include('partials.review-item')
                @empty
                    <p style="color:var(--muted);">No reviews yet. Be the first to visit and share your experience.</p>
                @endforelse
            </div>
        </div>

        <div>
            <div class="side-card">
                <h3 class="mt-0">Plan your visit</h3>
                <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Get Directions</a>
                @include('partials.check-in-button', ['type' => 'souvenir-centers', 'listing' => $souvenirCenter])

                @include('partials.map-embed', ['latitude' => $souvenirCenter->latitude, 'longitude' => $souvenirCenter->longitude, 'name' => $souvenirCenter->name])
            </div>
        </div>
    </div>

    @if ($nearby->count())
        <div class="section-tight">
            <div class="section-head">
                <div><h2>More in {{ $souvenirCenter->region?->name ?? 'this area' }}</h2></div>
            </div>
            <div class="card-grid">
                @foreach ($nearby as $n)
                    @include('partials.souvenir-card', ['souvenirCenter' => $n])
                @endforeach
            </div>
        </div>
    @endif
</div>

<div class="sticky-cta">
    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Get Directions</a>
</div>
@endsection
