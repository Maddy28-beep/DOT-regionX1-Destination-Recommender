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
    $mapUrl = $souvenirCenter->latitude && $souvenirCenter->longitude
        ? "https://www.google.com/maps/search/?api=1&query={$souvenirCenter->latitude},{$souvenirCenter->longitude}"
        : 'https://www.google.com/maps/search/?api=1&query='.urlencode($souvenirCenter->name.' '.$souvenirCenter->location);
@endphp

<div class="container">
    @include('partials.operating-status-notice', ['listing' => $souvenirCenter])
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('souvenir-centers.index') }}">Souvenir Centers</a> /
        {{ $souvenirCenter->name }}
    </nav>

    <x-promo-banner :promotions="\App\Models\Promotion::active()->forListing($souvenirCenter->getMorphClass(), $souvenirCenter->id)->latest()->get()" />

    @include('partials.gallery-hero', [
        'photos' => $souvenirCenter->photos,
        'title' => $souvenirCenter->name,
        'subtitle' => $souvenirCenter->posterMeta(),
        'isAccredited' => $souvenirCenter->is_accredited,
        'rating' => number_format($souvenirCenter->rating, 1),
        'reviewCount' => $souvenirCenter->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            @include('partials.listing-about-card', ['listing' => $souvenirCenter, 'noun' => 'souvenir center', 'groups' => []])

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
        <div class="section-tight">
            <div class="section-head">
                <div><h2>More in {{ $souvenirCenter->region?->name ?? 'this area' }}</h2></div>
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
