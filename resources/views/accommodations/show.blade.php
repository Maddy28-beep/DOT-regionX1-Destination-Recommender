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
    $mapUrl = $accommodation->latitude && $accommodation->longitude
        ? "https://www.google.com/maps/search/?api=1&query={$accommodation->latitude},{$accommodation->longitude}"
        : 'https://www.google.com/maps/search/?api=1&query='.urlencode($accommodation->name.' '.$accommodation->location);

    $tierLabel = match ($accommodation->price_tier) {
        'Budget-Friendly' => 'Budget',
        null, '' => null,
        default => $accommodation->price_tier,
    };
@endphp

<div class="container">
    @include('partials.operating-status-notice', ['listing' => $accommodation])
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('accommodations.index') }}">Accommodations</a> /
        {{ $accommodation->name }}
    </nav>

    <x-promo-banner :promotions="\App\Models\Promotion::active()->forListing($accommodation->getMorphClass(), $accommodation->id)->latest()->get()" />

    @include('partials.gallery-hero', [
        'photos' => $accommodation->photos,
        'title' => $accommodation->name,
        'subtitle' => $accommodation->posterMeta(),
        'isAccredited' => $accommodation->is_accredited,
        'rating' => number_format($accommodation->rating, 1),
        'reviewCount' => $accommodation->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            @include('partials.stat-strip', ['items' => [
                ['icon' => 'tag', 'label' => 'Price tier', 'text' => $tierLabel ?? '—', 'meter' => $accommodation->posterTier()],
                ['icon' => 'ticket', 'label' => 'Per night', 'text' => $accommodation->price_per_night ? number_format($accommodation->price_per_night) : '—', 'peso' => (bool) $accommodation->price_per_night],
                ['icon' => 'map-pin', 'label' => 'From city center', 'text' => $accommodation->distance_km ? rtrim(rtrim(number_format((float) $accommodation->distance_km, 1), '0'), '.').' km' : '—'],
            ]])

            @include('partials.listing-about-card', [
                'listing' => $accommodation,
                'noun' => 'accommodation',
                'groups' => ['Property type' => [$accommodation->type], 'DOT classification' => [$accommodation->dot_classification]],
            ])

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

            @include('partials.reviews-section', ['listing' => $accommodation, 'type' => 'accommodations', 'kind' => 'accommodation', 'emptyHint' => 'Be the first to stay and share your experience.'])
        </div>

        <div>
            @include('partials.listing-visit-card', [
                'listing' => $accommodation,
                'type' => 'accommodations',
                'mapUrl' => $mapUrl,
                'kicker' => 'Before you book',
                'title' => 'Plan your stay',
                'rows' => [
                    ['icon' => 'log-in', 'label' => 'Check-in', 'value' => $accommodation->check_in ?: 'Not listed'],
                    ['icon' => 'log-out', 'label' => 'Check-out', 'value' => $accommodation->check_out ?: 'Not listed'],
                ],
            ])
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
    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Get Directions</a>
    <x-save-heart type="accommodations" :listing="$accommodation" variant="button" class="cta-half" />
</div>
@endsection
