@extends('layouts.app')

@section('title', $package->name.' — ExploreDVO')

@section('content')
@php
    $gradients = [
        'linear-gradient(135deg,#ff6b35,#e2551f)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
    ];
    $gradient = $gradients[$package->id % count($gradients)];
    $operatorLinkable = $package->tourOperator && $package->tourOperator->is_accredited && ! $package->tourOperator->archived_at;
    $providerLabel = $package->tourOperator->name ?? $package->provider_name;
@endphp

<div class="container">
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('packages.index') }}">Tour Packages</a> /
        {{ $package->name }}
    </nav>

    @include('partials.gallery-hero', [
        'photos' => $package->photos,
        'title' => $package->name,
        'subtitle' => $package->duration_label.($package->region ? ' · '.$package->region->name : ''),
        'isAccredited' => $package->is_accredited,
        'rating' => number_format($package->rating, 1),
        'reviewCount' => $package->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            <div class="fact-grid">
                <div class="fact">
                    <div class="fact-val">&#8369;{{ number_format($package->price_per_pax ?? 0) }}</div>
                    <div class="fact-label">Per Person</div>
                </div>
                <div class="fact">
                    <div class="fact-val">{{ $package->duration_label ?? '—' }}</div>
                    <div class="fact-label">Duration</div>
                </div>
                <div class="fact">
                    <div class="fact-val">
                        @if ($operatorLinkable)
                            <a href="{{ route('tour-operators.show', $package->tourOperator) }}">{{ $providerLabel }}</a>
                        @else
                            {{ $providerLabel ?? '—' }}
                        @endif
                    </div>
                    <div class="fact-label">Provider</div>
                </div>
            </div>

            <div class="side-card">
                <h3 class="mt-0">About this package</h3>
                <p>{{ $package->description ?? 'No description available yet for this package.' }}</p>
            </div>

            @if ($package->inclusions->count())
                <div class="side-card">
                    <h3 class="mt-0">What's included</h3>
                    @foreach ($package->inclusions as $inclusion)
                        <div class="review-item">
                            <p class="comment" style="margin:0;">&#10003; {{ $inclusion->item }}</p>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="side-card">
                <h3 class="mt-0">Traveler Reviews ({{ $package->reviews->count() }})</h3>
                @forelse ($package->reviews as $review)
                    @include('partials.review-item')
                @empty
                    <p style="color:var(--muted);">No reviews yet.</p>
                @endforelse
            </div>
        </div>

        <div>
            <div class="side-card">
                <h3 class="mt-0">Plan this package</h3>
                <p style="font-size:.85rem; color:var(--muted); margin-top:0;">
                    Budget tier: {{ $package->price_tier ?? 'Not specified' }}<br>
                    Provided by: {{ $providerLabel ?? 'DOT-accredited operator' }}
                </p>
                <a href="{{ route('tourist.register') }}" class="btn btn-primary btn-block">Plan with this Package</a>
                @include('partials.check-in-button', ['type' => 'packages', 'listing' => $package])

                @include('partials.map-embed', ['latitude' => $package->latitude, 'longitude' => $package->longitude, 'name' => $package->name])
            </div>
        </div>
    </div>

    @if ($nearby->count())
        <div class="section-tight">
            <div class="section-head">
                <div><h2>More packages in {{ $package->region?->name ?? 'this area' }}</h2></div>
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
    <a href="{{ route('tourist.register') }}" class="btn btn-primary btn-block">Plan with this Package</a>
</div>
@endsection
