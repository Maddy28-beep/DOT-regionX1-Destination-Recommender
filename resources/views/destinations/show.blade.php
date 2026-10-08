@extends('layouts.app')

@section('title', $destination->name.' — ExploreDVO')

@section('content')
@php
    $mapUrl = $destination->latitude && $destination->longitude
        ? "https://www.google.com/maps/search/?api=1&query={$destination->latitude},{$destination->longitude}"
        : 'https://www.google.com/maps/search/?api=1&query='.urlencode($destination->name.' '.$destination->location);
@endphp

<div class="container">
    @include('partials.operating-status-notice', ['listing' => $destination])
    <nav class="breadcrumb">
        <a href="{{ route('home') }}">Home</a> /
        <a href="{{ route('destinations.index') }}">Destinations</a> /
        {{ $destination->name }}
    </nav>


    @include('partials.dest-detail-hero', ['destination' => $destination])

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
        <div class="section-tight">
            <div class="section-head">
                <div>
                    <h2 class="poster-title" style="color:var(--ocean-teal-dark);">
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
