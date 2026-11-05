@extends('layouts.app')

@section('title', $destination->displayName().' — ExploreDVO')
@section('meta_description', \Illuminate\Support\Str::limit(trim(strip_tags((string) ($destination->description ?? $destination->displayName().' on ExploreDVO, the DOT Region XI tourism platform.'))), 155))

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/accommodation-gallery.css') }}?v={{ filemtime(public_path('css/accommodation-gallery.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/destination-detail.css') }}?v={{ filemtime(public_path('css/destination-detail.css')) }}">
@endpush

@section('content')
@php
    $mapUrl = \App\Support\MapLinks::directionsTo($destination->latitude, $destination->longitude, $destination->name, $destination->location);
@endphp

<div class="container destination-detail-refresh listing-detail-refresh">
    @include('partials.operating-status-notice', ['listing' => $destination])
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('destinations.index') }}">Destinations</a> /
        {{ $destination->displayName() }}
    </nav>


    @if ($destination->photos->isNotEmpty())
        @include('partials.gallery-hero', [
            'photos' => $destination->photos,
            'title' => $destination->displayName(),
            'subtitle' => trim($destination->location.($destination->region ? ' · '.$destination->region->name : '')),
            'isAccredited' => $destination->is_accredited,
            'provenance' => app(\App\Support\SampleData::class)->status($destination),
            'rating' => number_format($destination->rating, 1),
            'reviewCount' => $destination->review_count,
            'fallbackGradient' => '#163b30',
        ])
    @else
        @include('partials.dest-detail-hero', ['destination' => $destination])
    @endif

    <div class="detail-layout">
        <div>
            @include('partials.dest-stat-strip', ['destination' => $destination])
            @include('partials.dest-about-card', ['destination' => $destination])

            @include('partials.reviews-section', ['listing' => $destination, 'type' => 'destinations', 'kind' => 'destination'])
        </div>

        <div>
            @include('partials.plan-visit-card', ['destination' => $destination, 'mapUrl' => $mapUrl])
        </div>
    </div>

    @if ($nearby->count())
        <div class="section-tight detail-related">
            <div class="section-head">
                <div>
                    <h2 class="detail-related__title">
                        @if ($nearbyIsSameRegion)
                            More in {{ $destination->region?->name ?? 'this area' }}
                        @else
                            You Might Also Like
                        @endif
                    </h2>
                </div>
            </div>
            <div class="dpost-grid">
                @foreach ($nearby as $n)
                    @include('partials.listing-poster-card', ['listing' => $n])
                @endforeach
            </div>
        </div>
    @endif
</div>

<div class="sticky-cta">
    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-poster-primary" style="flex:1;">Directions</a>
    <x-save-heart type="destinations" :listing="$destination" variant="button" class="cta-half" />
</div>
@endsection
