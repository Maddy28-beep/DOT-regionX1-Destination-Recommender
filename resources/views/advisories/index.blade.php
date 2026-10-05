@extends('layouts.app')

@section('title', 'Travel Advisories — ExploreDVO')

@section('content')

@php
    $levels = [
        'danger' => ['label' => 'Critical', 'icon' => 'alert-triangle'],
        'warning' => ['label' => 'Advisory', 'icon' => 'alert-triangle'],
        'info' => ['label' => 'Notice', 'icon' => 'megaphone'],
    ];

    // Where a place-specific advisory can send the traveller.
    $listingRoutes = [
        'destination' => 'destinations.show',
        'accommodation' => 'accommodations.show',
        'restaurant' => 'restaurants.show',
        'souvenir_center' => 'souvenir-centers.show',
        'package' => 'packages.show',
    ];
@endphp

<div class="page-head">
    <div class="container">
        <span class="poster-kicker">stay informed</span>
        <h1 class="poster-title">Travel Advisories</h1>
        <p>
            @if ($totalActive > 0)
                {{ $totalActive }} active {{ \Illuminate\Support\Str::plural('notice', $totalActive) }} for the Davao Region from DOT Region XI.
                Last updated {{ $lastUpdated->diffForHumans() }}.
            @else
                Current weather, safety, and travel notices for the Davao Region, issued by DOT Region XI.
            @endif
        </p>
    </div>
</div>

<div class="section-tight">
    <div class="container">

        @if ($totalActive > 0)
            <div class="advisory-tiles" role="group" aria-label="Filter advisories by level">
                @foreach ($levels as $key => $meta)
                    @php
                        $count = (int) $countsBySeverity->get($key, 0);
                        $selected = $activeSeverity === $key;
                    @endphp
                    <a href="{{ $selected ? route('advisories.index') : route('advisories.index', ['severity' => $key]) }}"
                       class="advisory-tile advisory-tile--{{ $key }} {{ $selected ? 'is-selected' : '' }} {{ $count === 0 ? 'is-empty' : '' }}"
                       @if($selected) aria-current="true" @endif>
                        <span class="advisory-tile__icon"><x-icon :name="$meta['icon']" /></span>
                        <span>
                            <span class="advisory-tile__count">{{ $count }}</span>
                            <span class="advisory-tile__label">{{ $meta['label'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
            <p class="advisory-tiles__hint">
                @if ($activeSeverity)
                    Showing {{ $levels[$activeSeverity]['label'] }} only &mdash; <a href="{{ route('advisories.index') }}">show all {{ $totalActive }}</a>.
                @else
                    Showing all {{ $totalActive }}. Select a level to filter, select it again to clear.
                @endif
            </p>
        @endif

        @if ($advisories->isEmpty())
            <div class="advisory-clear">
                <div class="advisory-clear__icon"><x-icon name="shield-check" /></div>
                @if ($activeSeverity)
                    <h3>Nothing at the {{ $levels[$activeSeverity]['label'] }} level right now</h3>
                    <p>Nothing is posted at this level &mdash; <a href="{{ route('advisories.index') }}">view all advisories</a>.</p>
                @else
                    <h3>All clear for now</h3>
                    <p>No active advisories for the Davao Region. We'll post here first if anything changes, so check again before you travel.</p>
                @endif
            </div>
        @else
            <div class="advisory-list">
                @foreach ($advisories as $advisory)
                    @php
                        $level = $levels[$advisory->severity] ?? $levels['warning'];
                        $listing = $advisory->listing;
                        $listingRoute = $listing ? ($listingRoutes[$advisory->listing_kind] ?? null) : null;
                    @endphp
                    <article class="advisory-card advisory-card--{{ $advisory->severity }}" id="advisory-{{ $advisory->id }}">
                        <header class="advisory-card__band">
                            <x-icon :name="$level['icon']" />
                            <span>{{ $level['label'] }}</span>
                            <time datetime="{{ $advisory->updated_at->toIso8601String() }}" title="{{ $advisory->updated_at->format('M j, Y \\a\\t g:i A') }}">Updated {{ $advisory->updated_at->diffForHumans() }}</time>
                        </header>
                        <div class="advisory-card__body">
                            <h3 class="advisory-card__title">{{ $advisory->title }}</h3>
                            <p class="advisory-card__message">{{ $advisory->message }}</p>
                            <div class="advisory-card__meta">
                                <span class="advisory-pill"><x-icon name="map-pin" />{{ $listing->name ?? 'All of the Davao Region' }}</span>
                                <span class="advisory-pill"><x-icon name="calendar" />{{ $advisory->ends_at ? 'Until '.$advisory->ends_at->format('M j') : 'Until further notice' }}</span>
                                <span class="advisory-pill"><x-icon name="shield-check" />DOT Region XI</span>
                                @if ($listingRoute)
                                    <a href="{{ route($listingRoute, $listing) }}" class="advisory-card__link">View {{ $listing->name }} <span aria-hidden="true">&rarr;</span></a>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</div>

@endsection
