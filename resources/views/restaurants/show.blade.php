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

    $tierLabel = match ($restaurant->price_tier) {
        'Budget-Friendly' => 'Budget',
        null, '' => null,
        default => $restaurant->price_tier,
    };

    $hoursText = $restaurant->opening_hours ?: null;
    $openNow = \App\Support\OpeningHours::isOpenNow($hoursText);
    $pill = $restaurant->isClosedByStatus(now()) ? 'closed' : ($openNow === true ? 'open' : ($openNow === false ? 'closed' : null));

    $strip = [
        ['icon' => 'tag', 'label' => 'Price tier', 'text' => $tierLabel ?? '—', 'meter' => $restaurant->posterTier()],
        ['icon' => 'utensils', 'label' => 'Cuisine', 'text' => $restaurant->cuisine_type ?: '—', 'wrap' => true],
    ];
    if ($restaurant->contact_number) {
        $strip[] = ['icon' => 'phone', 'label' => 'Contact', 'text' => $restaurant->contact_number, 'wrap' => true];
    }
@endphp

<div class="container">
    @include('partials.operating-status-notice', ['listing' => $restaurant])
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('restaurants.index') }}">Restaurants</a> /
        {{ $restaurant->name }}
    </nav>

    <x-promo-banner :promotions="\App\Models\Promotion::active()->forListing($restaurant->getMorphClass(), $restaurant->id)->latest()->get()" />

    @include('partials.gallery-hero', [
        'photos' => $restaurant->photos,
        'title' => $restaurant->name,
        'subtitle' => $restaurant->posterMeta(),
        'isAccredited' => $restaurant->is_accredited,
        'rating' => number_format($restaurant->rating, 1),
        'reviewCount' => $restaurant->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            @include('partials.stat-strip', ['items' => $strip])

            @include('partials.listing-about-card', [
                'listing' => $restaurant,
                'noun' => 'restaurant',
                'groups' => [],
            ])

            @include('partials.reviews-section', ['listing' => $restaurant, 'type' => 'restaurants', 'kind' => 'restaurant', 'emptyHint' => 'Be the first to dine and share your experience.'])
        </div>

        <div>
            @include('partials.listing-visit-card', [
                'listing' => $restaurant,
                'type' => 'restaurants',
                'mapUrl' => $mapUrl,
                'kicker' => 'Before you go',
                'title' => 'Plan your visit',
                'rows' => [
                    ['icon' => 'clock', 'label' => 'Hours', 'value' => $hoursText ?? 'Contact establishment', 'pill' => $pill],
                ],
            ])
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
    <x-save-heart type="restaurants" :listing="$restaurant" variant="button" class="cta-half" />
</div>
@endsection
