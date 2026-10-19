@extends('layouts.app')

@section('title', 'Restaurants — ExploreDVO')

@section('content')
<header class="catalog-banner catalog-banner--restaurants">
    <div class="container">
        <h1 class="poster-title">Taste Davao</h1>
        <p>Explore local flavors and fresh seafood.</p>
    </div>
</header>

<div class="section-tight">
    <div class="container">

        @php
            $featuredTypes = collect(['Filipino', 'Seafood', 'Cafe', 'Japanese'])
                ->filter(fn ($type) => $cuisineTypes->contains($type))
                ->mapWithKeys(fn ($type) => [$type => $type]);
            $moreTypes = $cuisineTypes->reject(fn ($type) => $featuredTypes->has($type));
            $moreTypeSelected = $moreTypes->contains(request('cuisine_type'));
        @endphp
        @include('partials.catalog-categories', [
            'categoryLabel' => 'Restaurant cuisines',
            'categoryParam' => 'cuisine_type',
            'allCategoriesLabel' => 'All Cuisines',
            'moreCategoriesLabel' => 'More cuisines',
        ])

        <div class="catalog-layout">
            @php $activeFilters = \App\Support\ActiveFilters::from(request(), $regions, 'cuisine_type'); @endphp

            @include('partials.catalog-filters', [
                'route' => 'restaurants.index',
                'placeholder' => 'e.g. Marina Tuna',
                'regions' => $regions,
                'activeFilters' => $activeFilters,
                'tiers' => [['Budget-Friendly', 'Budget-friendly', ''], ['Mid-range', 'Mid-range', ''], ['Premium', 'Premium', '']],
                'anyBudget' => true,
                'categoryParam' => 'cuisine_type',
            ])

            <div>
                @include('partials.catalog-toolbar', [
                    'results' => $restaurants,
                    'noun' => 'restaurant',
                    'sortOptions' => ['recommended' => 'Recommended', 'rating' => 'Highest Rated', 'name' => 'Name (A–Z)'],
                    'activeFilters' => $activeFilters,
                    'viewToggle' => 'grid-map',
                ])

                @if ($restaurants->count())
                    <div class="card-grid" data-view-panel="grid">
                        @foreach ($restaurants as $restaurant)
                            @include('partials.listing-poster-card', ['listing' => $restaurant])
                        @endforeach
                    </div>

                    <div class="results-map-panel" data-view-panel="map" hidden>
                        @include('partials.listing-results-map', ['listings' => $restaurants, 'id' => 'restaurants-map'])
                    </div>

                    @include('partials.pagination', ['paginator' => $restaurants, 'panel' => 'grid'])
                @else
                    <x-davo-empty-state title="No restaurants match your filters" message="Try another category or clear your filters to explore more options." :action-url="route('restaurants.index')" action-label="Clear filters" />
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
