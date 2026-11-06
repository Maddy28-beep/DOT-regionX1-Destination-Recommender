@extends('layouts.app')

@section('title', $tourOperator->name.' — ExploreDVO')
@section('meta_description', \Illuminate\Support\Str::limit(trim(strip_tags((string) ($tourOperator->description ?? $tourOperator->name.' on ExploreDVO, the DOT Region XI tourism platform.'))), 155))

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/accommodation-gallery.css') }}?v={{ filemtime(public_path('css/accommodation-gallery.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/destination-detail.css') }}?v={{ filemtime(public_path('css/destination-detail.css')) }}">
@endpush

@section('content')
@php
    $gradients = [
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
    ];
    $gradient = $gradients[$tourOperator->id % count($gradients)];
    $mapUrl = \App\Support\MapLinks::directionsTo($tourOperator->latitude, $tourOperator->longitude, $tourOperator->name, $tourOperator->location);
    $tierLabel = match ($tourOperator->price_tier) {
        'Budget-Friendly' => 'Budget',
        null, '' => null,
        default => $tourOperator->price_tier,
    };
    $packageCount = $tourOperator->packages->count();
    $phone = $tourOperator->contact_number ?: null;
@endphp

<div class="container operator-detail-refresh listing-detail-refresh standard-gallery-detail">
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('tour-operators.index') }}">Tour Operators</a> /
        {{ $tourOperator->name }}
    </nav>

    @include('partials.gallery-hero', [
        'keepOtherPhotos' => true,
        'photos' => $tourOperator->photos,
        'title' => $tourOperator->name,
        'subtitle' => $tourOperator->posterMeta(),
        'isAccredited' => $tourOperator->is_accredited,
        'isSample' => app(\App\Support\SampleData::class)->isSample($tourOperator),
        'provenance' => app(\App\Support\SampleData::class)->status($tourOperator),
        'rating' => number_format($tourOperator->rating, 1),
        'reviewCount' => $tourOperator->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            <x-promo-banner :promotions="\App\Models\Promotion::active()->forListing($tourOperator->getMorphClass(), $tourOperator->id)->latest()->get()" />
            @include('partials.stat-strip', ['items' => [
                ['icon' => 'tag', 'label' => 'Price tier', 'text' => $tierLabel ?? '—', 'meter' => $tourOperator->posterTier()],
                ['icon' => 'compass', 'label' => 'Specialization', 'text' => $tourOperator->specialization ?: '—', 'wrap' => true],
                ['icon' => 'grid', 'label' => 'Tour packages', 'text' => $packageCount.' '.\Illuminate\Support\Str::plural('package', $packageCount)],
            ]])

            @include('partials.listing-about-card', [
                'listing' => $tourOperator,
                'noun' => 'tour operator',
                'groups' => [],
            ])

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

            @include('partials.reviews-section', ['listing' => $tourOperator, 'type' => 'tour-operators', 'kind' => 'tour_operator', 'emptyHint' => 'Be the first to book and share your experience.'])
        </div>

        <div>
            @include('partials.listing-visit-card', [
                'listing' => $tourOperator,
                'mapUrl' => $mapUrl,
                'kicker' => 'Before you book',
                'title' => 'Get in touch',
                'rows' => [
                    ['icon' => 'phone', 'label' => 'Contact', 'value' => $phone ?? 'Not provided', 'href' => $phone ? 'tel:'.preg_replace('/[^\d+]/', '', $phone) : null],
                ],
            ])
        </div>
    </div>

    @if ($nearby->count())
        <div class="section-tight detail-related">
            <div class="section-head">
                <div><h2 class="detail-related__title">More in {{ $tourOperator->region?->name ?? 'this area' }}</h2></div>
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
