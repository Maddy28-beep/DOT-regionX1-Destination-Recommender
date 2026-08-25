@extends('layouts.app')

@section('title', $tourOperator->name.' — ExploreDVO')

@section('content')
@php
    $gradients = [
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
    ];
    $gradient = $gradients[$tourOperator->id % count($gradients)];
    $mapUrl = $tourOperator->latitude && $tourOperator->longitude
        ? "https://www.google.com/maps/search/?api=1&query={$tourOperator->latitude},{$tourOperator->longitude}"
        : 'https://www.google.com/maps/search/?api=1&query='.urlencode($tourOperator->name.' '.$tourOperator->location);
@endphp

<div class="container">
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('tour-operators.index') }}">Tour Operators</a> /
        {{ $tourOperator->name }}
    </nav>

    @include('partials.gallery-hero', [
        'photos' => $tourOperator->photos,
        'title' => $tourOperator->name,
        'subtitle' => $tourOperator->location.($tourOperator->region ? ' · '.$tourOperator->region->name : ''),
        'isAccredited' => $tourOperator->is_accredited,
        'rating' => number_format($tourOperator->rating, 1),
        'reviewCount' => $tourOperator->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            <div class="fact-grid">
                <div class="fact">
                    <div class="fact-val">{{ $tourOperator->price_tier ?? '—' }}</div>
                    <div class="fact-label">Budget Tier</div>
                </div>
                <div class="fact">
                    <div class="fact-val">{{ $tourOperator->specialization ?? '—' }}</div>
                    <div class="fact-label">Specialization</div>
                </div>
                <div class="fact">
                    <div class="fact-val">{{ $tourOperator->contact_number ?? '—' }}</div>
                    <div class="fact-label">Contact</div>
                </div>
            </div>

            <div class="side-card">
                <h3 class="mt-0">About {{ $tourOperator->name }}</h3>
                <p>{{ $tourOperator->description ?? 'No description available yet for this tour operator.' }}</p>
            </div>

            @if ($tourOperator->packages->isNotEmpty())
                <div class="side-card">
                    <h3 class="mt-0">Tour Packages by {{ $tourOperator->name }}</h3>
                    @foreach ($tourOperator->packages as $package)
                        <div class="review-item">
                            <div class="author"><a href="{{ route('packages.show', $package) }}">{{ $package->name }}</a></div>
                            <p class="comment">
                                {{ $package->duration_label ?? 'Duration not specified' }}
                                @if ($package->price_per_pax) &middot; &#8369;{{ number_format($package->price_per_pax) }} / pax @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="side-card">
                <h3 class="mt-0">Traveler Reviews ({{ $tourOperator->reviews->count() }})</h3>
                @forelse ($tourOperator->reviews as $review)
                    @include('partials.review-item')
                @empty
                    <p style="color:var(--muted);">No reviews yet. Be the first to book and share your experience.</p>
                @endforelse
            </div>
        </div>

        <div>
            <div class="side-card">
                <h3 class="mt-0">Get in touch</h3>
                <p style="font-size:.85rem; color:var(--muted); margin-top:0;">
                    Contact: {{ $tourOperator->contact_number ?? 'Not provided' }}<br>
                    Budget tier: {{ $tourOperator->price_tier ?? 'Not specified' }}
                </p>
                <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Get Directions</a>
                @include('partials.check-in-button', ['type' => 'tour-operators', 'listing' => $tourOperator])

                @include('partials.map-embed', ['latitude' => $tourOperator->latitude, 'longitude' => $tourOperator->longitude, 'name' => $tourOperator->name])
            </div>
        </div>
    </div>

    @if ($nearby->count())
        <div class="section-tight">
            <div class="section-head">
                <div><h2>More in {{ $tourOperator->region?->name ?? 'this area' }}</h2></div>
            </div>
            <div class="card-grid">
                @foreach ($nearby as $n)
                    @include('partials.tour-operator-card', ['tourOperator' => $n])
                @endforeach
            </div>
        </div>
    @endif
</div>

<div class="sticky-cta">
    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Get Directions</a>
</div>
@endsection
