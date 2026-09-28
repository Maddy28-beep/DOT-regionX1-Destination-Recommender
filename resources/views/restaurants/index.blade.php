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
            <aside class="filter-panel" id="filterPanel">
                <h3>Filter results</h3>
                <form method="GET" action="{{ route('restaurants.index') }}">
                    <input type="hidden" name="cuisine_type" value="{{ request('cuisine_type') }}">

                    <div class="field">
                        <label for="q">Search by name</label>
                        <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="e.g. Marina Tuna">
                    </div>

                    <div class="field">
                        <label for="region_id">Province / City</label>
                        <select id="region_id" name="region_id">
                            <option value="">All regions</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region->id }}" @selected(request('region_id') == $region->id)>{{ $region->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="price_tier">Budget</label>
                        <select id="price_tier" name="price_tier">
                            <option value="">Any budget</option>
                            <option value="Budget-Friendly" @selected(request('price_tier') === 'Budget-Friendly')>Budget-Friendly</option>
                            <option value="Mid-range" @selected(request('price_tier') === 'Mid-range')>Mid-range</option>
                            <option value="Premium" @selected(request('price_tier') === 'Premium')>Premium</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="sort">Sort by</label>
                        <select id="sort" name="sort">
                            <option value="recommended" @selected(request('sort', 'recommended') === 'recommended')>Recommended</option>
                            <option value="rating" @selected(request('sort') === 'rating')>Highest Rated</option>
                            <option value="name" @selected(request('sort') === 'name')>Name (A&ndash;Z)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-poster-primary btn-block">Apply Filters</button>
                    @if (request()->anyFilled(['q', 'region_id', 'price_tier', 'cuisine_type']))
                        <a href="{{ route('restaurants.index') }}" class="btn btn-poster-ghost btn-block" style="margin-top:8px;">Clear all</a>
                    @endif
                </form>
            </aside>

            <div>
                <div class="results-bar">
                    <div class="results-count">{{ $restaurants->total() }} restaurant{{ $restaurants->total() === 1 ? '' : 's' }} found</div>
                </div>

                @if ($restaurants->count())
                    <div class="card-grid">
                        @foreach ($restaurants as $restaurant)
                            @include('partials.listing-poster-card', ['listing' => $restaurant])
                        @endforeach
                    </div>

                    <div class="pagination">
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
