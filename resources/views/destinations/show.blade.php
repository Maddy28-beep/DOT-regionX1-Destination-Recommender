@extends('layouts.app')

@section('title', $destination->name.' — ExploreDVO')

@section('content')
@php
    $gradients = [
        'linear-gradient(135deg,#0b6b4f,#14876a)',
        'linear-gradient(135deg,#ff6b35,#e2551f)',
        'linear-gradient(135deg,#1d6fa5,#0b4d75)',
        'linear-gradient(135deg,#7a4fc9,#4f2f96)',
        'linear-gradient(135deg,#c9932f,#916b19)',
        'linear-gradient(135deg,#2f9e8f,#1b6b60)',
    ];
    $gradient = $gradients[$destination->id % count($gradients)];
    $mapUrl = $destination->latitude && $destination->longitude
        ? "https://www.google.com/maps/search/?api=1&query={$destination->latitude},{$destination->longitude}"
        : 'https://www.google.com/maps/search/?api=1&query='.urlencode($destination->name.' '.$destination->location);
@endphp

<div class="container">
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('destinations.index') }}">Destinations</a> /
        {{ $destination->name }}
    </nav>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @include('partials.gallery-hero', [
        'photos' => $destination->photos,
        'title' => $destination->name,
        'subtitle' => $destination->location.($destination->region ? ' · '.$destination->region->name : ''),
        'isAccredited' => $destination->is_accredited,
        'rating' => number_format($destination->rating, 1),
        'reviewCount' => $destination->review_count,
        'fallbackGradient' => $gradient,
    ])

    <div class="detail-layout">
        <div>
            <div class="fact-grid">
                <div class="fact">
                    <div class="fact-val">{{ $destination->price_tier ?? '—' }}</div>
                    <div class="fact-label">Budget Tier</div>
                </div>
                <div class="fact">
                    <div class="fact-val">
                        @if (($destination->entry_fee_min ?? 0) == 0 && ($destination->entry_fee_max ?? 0) == 0)
                            Free
                        @else
                            &#8369;{{ number_format($destination->entry_fee_min ?? 0) }}&ndash;{{ number_format($destination->entry_fee_max ?? 0) }}
                        @endif
                    </div>
                    <div class="fact-label">Entry Fee</div>
                </div>
                <div class="fact">
                    <div class="fact-val">{{ $destination->distance_km ? number_format($destination->distance_km, 1).' km' : '—' }}</div>
                    <div class="fact-label">From City Center</div>
                </div>
            </div>

            <div class="side-card">
                <h3 class="mt-0">About {{ $destination->name }}</h3>
                <p>{{ $destination->description ?? 'No description available yet for this destination.' }}</p>

                @if ($destination->tags->count())
                    <div class="dest-tags" style="margin-top:14px;">
                        @foreach ($destination->tags as $tag)
                            <span class="dest-tag">{{ $tag->value }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="side-card">
                <h3 class="mt-0">Traveler Reviews ({{ $destination->reviews->count() }})</h3>
                @forelse ($destination->reviews as $review)
                    @include('partials.review-item')
                @empty
                    <p style="color:var(--muted);">No reviews yet. Be the first to visit and share your experience.</p>
                @endforelse
            </div>
        </div>

        <div>
            <div class="side-card">
                <h3 class="mt-0">Plan your visit</h3>
                <p style="font-size:.85rem; color:var(--muted); margin-top:0;">
                    Best time to visit: {{ $destination->best_time ?? 'Year-round' }}<br>
                    Hours: {{ $destination->hours ?? 'Contact establishment' }}<br>
                    Suggested duration: {{ $destination->visit_duration ?? 'Half day' }}
                </p>

                @auth('tourist')
                    <form method="POST" action="{{ route('destinations.save', $destination) }}">
                        @csrf
                        <button type="submit" class="btn {{ $isSaved ? 'btn-outline' : 'btn-primary' }} btn-block">
                            <x-icon name="heart" :filled="$isSaved" /> {{ $isSaved ? 'Saved to My Trip' : 'Save to My Trip' }}
                        </button>
                    </form>
                @else
                    <a href="{{ route('tourist.login') }}" class="btn btn-primary btn-block">Sign in to save</a>
                @endauth

                <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-outline btn-block" style="margin-top:10px;">Get Directions</a>
                @include('partials.check-in-button', ['type' => 'destinations', 'listing' => $destination])

                @include('partials.map-embed', ['latitude' => $destination->latitude, 'longitude' => $destination->longitude, 'name' => $destination->name])
            </div>
        </div>
    </div>

    @if ($nearby->count())
        <div class="section-tight">
            <div class="section-head">
                <div>
                    <h2>More in {{ $destination->region?->name ?? 'this area' }}</h2>
                </div>
            </div>
            <div class="card-grid">
                @foreach ($nearby as $n)
                    @include('partials.dest-card', ['destination' => $n])
                @endforeach
            </div>
        </div>
    @endif
</div>

<div class="sticky-cta">
    @auth('tourist')
        <form method="POST" action="{{ route('destinations.save', $destination) }}" style="flex:1;">
            @csrf
            <button type="submit" class="btn {{ $isSaved ? 'btn-outline' : 'btn-primary' }} btn-block">
                <x-icon name="heart" :filled="$isSaved" /> {{ $isSaved ? 'Saved' : 'Save to My Trip' }}
            </button>
        </form>
    @else
        <a href="{{ route('tourist.login') }}" class="btn btn-primary btn-block" style="flex:1;">Sign in to save</a>
    @endauth
    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-outline" style="flex:1;">Directions</a>
</div>
@endsection
