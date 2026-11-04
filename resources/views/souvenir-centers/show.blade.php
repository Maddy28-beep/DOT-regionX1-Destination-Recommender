@extends('layouts.app')

@section('title', $souvenirCenter->name.' — ExploreDVO')
@section('meta_description', \Illuminate\Support\Str::limit(trim(strip_tags((string) ($souvenirCenter->description ?? $souvenirCenter->name.' on ExploreDVO, the DOT Region XI tourism platform.'))), 155))

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/accommodation-gallery.css') }}?v={{ filemtime(public_path('css/accommodation-gallery.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/destination-detail.css') }}?v={{ filemtime(public_path('css/destination-detail.css')) }}">
@endpush

@section('content')
@php
    $gradients = [
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
    ];
    $gradient = $gradients[$souvenirCenter->id % count($gradients)];
    $mapUrl = \App\Support\MapLinks::directionsTo($souvenirCenter->latitude, $souvenirCenter->longitude, $souvenirCenter->name, $souvenirCenter->location);
@endphp

<div class="container souvenir-detail-refresh listing-detail-refresh standard-gallery-detail">
    @include('partials.operating-status-notice', ['listing' => $souvenirCenter])
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('souvenir-centers.index') }}">Souvenir Centers</a> /
        {{ $souvenirCenter->name }}
    </nav>

    <x-promo-banner :promotions="\App\Models\Promotion::active()->forListing($souvenirCenter->getMorphClass(), $souvenirCenter->id)->latest()->get()" />

    @include('partials.gallery-hero', [
        'keepOtherPhotos' => true,
        'photos' => $souvenirCenter->photos,
        'title' => $souvenirCenter->name,
        'subtitle' => $souvenirCenter->posterMeta(),
        'isAccredited' => $souvenirCenter->is_accredited,
        'isSample' => app(\App\Support\SampleData::class)->isSample($souvenirCenter),
        'provenance' => app(\App\Support\SampleData::class)->status($souvenirCenter),
        'rating' => number_format($souvenirCenter->rating, 1),
        'reviewCount' => $souvenirCenter->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            @include('partials.listing-about-card', ['listing' => $souvenirCenter, 'noun' => 'souvenir center', 'groups' => []])

            @include('partials.listing-amenities-card', ['listing' => $souvenirCenter, 'kind' => 'souvenir_center'])

            @include('partials.reviews-section', ['listing' => $souvenirCenter, 'type' => 'souvenir-centers', 'kind' => 'souvenir_center'])
        </div>

        <div>
            @include('partials.listing-visit-card', [
                'listing' => $souvenirCenter,
                'type' => 'souvenir-centers',
                'mapUrl' => $mapUrl,
                'kicker' => 'Before you go',
                'title' => 'Plan your visit',
                'rows' => [],
            ])
        </div>
    </div>

    @if ($nearby->count())
        <div class="section-tight detail-related">
            <div class="section-head">
                <div><h2 class="detail-related__title">More in {{ $souvenirCenter->region?->name ?? 'this area' }}</h2></div>
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
    <x-save-heart type="souvenir-centers" :listing="$souvenirCenter" variant="button" class="cta-half" />
</div>
@endsection
