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

        <button type="button" class="filter-toggle" onclick="document.getElementById('filterPanel').classList.toggle('open')">
            <x-icon name="filter" /> Filters
        </button>

        <div class="catalog-layout">
            @php $activeFilters = \App\Support\ActiveFilters::from(request(), $regions, 'cuisine_type'); @endphp

            @include('partials.catalog-filters', [
                'route' => 'restaurants.index',
                'placeholder' => 'e.g. Marina Tuna',
                'regions' => $regions,
                'activeFilters' => $activeFilters,
                'tiers' => [['Budget-Friendly', 'Budget', '₱'], ['Mid-range', 'Mid-range', '₱₱'], ['Premium', 'Premium', '₱₱₱']],
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

                    <div class="pagination" data-view-panel="grid">
                        @if ($restaurants->onFirstPage())
                            <span class="disabled">&laquo;</span>
                        @else
                            <a href="{{ $restaurants->previousPageUrl() }}">&laquo;</a>
                        @endif

                        @foreach ($restaurants->getUrlRange(1, $restaurants->lastPage()) as $page => $url)
                            <span class="{{ $page === $restaurants->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></span>
                        @endforeach

                        @if ($restaurants->hasMorePages())
                            <a href="{{ $restaurants->nextPageUrl() }}">&raquo;</a>
                        @else
                            <span class="disabled">&raquo;</span>
                        @endif
                    </div>
                @else
                    <div class="empty-state">
                        <p><strong>No restaurants match your filters.</strong></p>
                        <a href="{{ route('restaurants.index') }}" class="btn btn-outline">Clear filters</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
