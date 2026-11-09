@extends('layouts.app')

@section('title', 'Accommodations — ExploreDVO')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/stays-catalog.css') }}?v={{ filemtime(public_path('css/stays-catalog.css')) }}">
@endpush

@section('content')
<script src="{{ asset('js/stays-categories.js') }}?v={{ filemtime(public_path('js/stays-categories.js')) }}" defer></script>
<div class="stays-catalog catalog-refresh">
<header class="catalog-banner catalog-banner--accommodations">
    <div class="container">
        <h1 class="poster-title">Stay in Davao</h1>
        <p>Find your perfect stay, from city hotels to island resorts.</p>
    </div>
</header>

<div class="section-tight">
    <div class="container">

        @php
            $featuredTypes = collect(['Hotel', 'Resort', 'Beach Resort', 'Homestay'])
                ->filter(fn ($type) => $types->contains($type))
                ->mapWithKeys(fn ($type) => [$type => $type]);
            $moreTypes = $types->reject(fn ($type) => $featuredTypes->has($type));
            $moreTypeSelected = $moreTypes->contains(request('type'));
        @endphp
        @include('partials.catalog-categories', ['categoryLabel' => 'Accommodation categories'])

        <div class="catalog-layout">
            @php $activeFilters = \App\Support\ActiveFilters::from(request(), $regions, 'type'); @endphp

            @include('partials.catalog-filters', [
                'hidden' => \App\Support\ActiveFilters::carry(request(), ['price_min','price_max','stay_mode','from','to','adults','children','seniors','pwd']),
                'route' => 'accommodations.index',
                'placeholder' => 'e.g. Pearl Farm',
                'regions' => $regions,
                'activeFilters' => $activeFilters,
                'tiers' => [['Budget-Friendly', 'Budget-Friendly', ''], ['Mid-range', 'Mid-range', ''], ['Premium', 'Premium', '']],
                'anyBudget' => true,
                'categoryParam' => 'type',
            ])

            <div>
                @include('partials.catalog-toolbar', [
                    'results' => $accommodations,
                    'noun' => 'accommodation',
                    'sortOptions' => ['recommended' => 'Recommended', 'rating' => 'Highest Rated', 'price_low' => 'Price: Low to High', 'price_high' => 'Price: High to Low', 'name' => 'Name (A–Z)'],
                    'activeFilters' => $activeFilters,
                    'viewToggle' => 'grid-map',
                ])

                @if ($accommodations->count())
                    <div class="card-grid" data-view-panel="grid">
                        @foreach ($accommodations as $accommodation)
                            @include('partials.listing-poster-card', ['listing' => $accommodation])
                        @endforeach
                    </div>

                    <div class="results-map-panel" data-view-panel="map" hidden>
                        @include('partials.listing-results-map', ['listings' => $accommodations, 'id' => 'accommodations-map'])
                    </div>

                    @include('partials.pagination', ['paginator' => $accommodations, 'panel' => 'grid'])
                @else
                    <x-davo-empty-state title="No accommodations match your filters" :message="request('stay_mode') === 'day_use' ? 'No listing says it offers day use yet. That is not recorded as its own detail, so this reads the listing text. Try Any or Overnight.' : 'Try another category or clear your filters to explore more options.'" :action-url="route('accommodations.index')" action-label="Clear filters" />
                @endif
            </div>
        </div>
    </div>
</div>
</div>
@endsection
