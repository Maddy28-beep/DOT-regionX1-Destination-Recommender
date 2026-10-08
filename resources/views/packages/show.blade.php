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
    $tierLabel = match ($package->price_tier) {
        'Budget-Friendly' => 'Budget',
        null, '' => null,
        default => $package->price_tier,
    };
    $hasSchedule = $package->itineraryDays->isNotEmpty();
@endphp

<div class="container">
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('packages.index') }}">Tour Packages</a> /
        {{ $package->name }}
    </nav>

    <x-promo-banner :promotions="\App\Models\Promotion::active()->forListing($package->getMorphClass(), $package->id)->latest()->get()" />

    @include('partials.gallery-hero', [
        'photos' => $package->photos,
        'title' => $package->name,
        'subtitle' => $package->posterMeta(),
        'isAccredited' => $package->is_accredited,
        'rating' => number_format($package->rating, 1),
        'reviewCount' => $package->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            @include('partials.stat-strip', ['items' => [
                ['icon' => 'tag', 'label' => 'Price tier', 'text' => $tierLabel ?? '—', 'meter' => $package->posterTier()],
                ['icon' => 'ticket', 'label' => 'Per person', 'text' => $package->price_per_pax ? number_format($package->price_per_pax) : '—', 'peso' => (bool) $package->price_per_pax],
                ['icon' => 'timer', 'label' => 'Duration', 'text' => $package->duration_label ?: '—', 'wrap' => true],
            ]])

            @include('partials.listing-about-card', [
                'listing' => $package,
                'noun' => 'package',
                'groups' => ['Package type' => [$package->type]],
            ])

            @if ($package->itineraryDays->isNotEmpty())
                <div class="side-card">
                    <h3 class="mt-0">Day-by-Day Itinerary</h3>
                    @foreach ($package->itineraryDays as $day)
                        <div class="itinerary-day">
                            <h3>Day {{ $day->day_number }}</h3>
                            <div class="day-timeline">
                                <div class="itinerary-item">
                                    <div class="itinerary-item__body">
                                        <strong>{{ $day->title }}</strong>
                                        @if ($day->description)
                                            <div class="sub">{{ $day->description }}</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

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

            @include('partials.reviews-section', ['listing' => $package, 'type' => 'packages', 'kind' => 'package', 'emptyHint' => 'Be the first to try it and share your experience.'])
        </div>

        <div>
            @include('partials.listing-visit-card', [
                'listing' => $package,
                'mapUrl' => null,
                'kicker' => 'Before you book',
                'title' => 'Plan this package',
                'rows' => [
                    ['icon' => 'building', 'label' => 'Provided by', 'value' => $providerLabel ?? 'DOT-accredited operator', 'href' => $operatorLinkable ? route('tour-operators.show', $package->tourOperator) : null],
                ],
                'primary' => $hasSchedule
                    ? ['label' => 'Plan with this Package', 'icon' => 'compass', 'post' => route('packages.plan-with', $package)]
                    : ['label' => 'Plan My Trip', 'icon' => 'compass', 'href' => route('plan.edit'), 'hint' => "This provider hasn't published a day-by-day schedule for this package yet."],
            ])
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
    @if ($package->itineraryDays->isNotEmpty())
        <form method="POST" action="{{ route('packages.plan-with', $package) }}">
            @csrf
            <button type="submit" class="btn btn-primary btn-block">Plan with this Package</button>
        </form>
    @else
        <a href="{{ route('plan.edit') }}" class="btn btn-primary btn-block">Plan My Trip</a>
    @endif
</div>
@endsection
