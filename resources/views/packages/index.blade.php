@extends('layouts.app')

@section('title', 'Tour Packages — ExploreDVO')

@section('content')
<header class="catalog-banner catalog-banner--packages">
    <div class="container">
        <h1 class="poster-title">Explore Davao Together</h1>
        <p>Find your next adventure with local tour packages.</p>
    </div>
</header>

<div class="section-tight">
    <div class="container">

        @php
            $featuredTypes = collect(['Nature & Adventure', 'Beach & Island', 'Adventure & Hiking', 'Cultural Heritage'])
                ->filter(fn ($type) => $types->contains($type))
                ->mapWithKeys(fn ($type) => [$type => $type]);
            $moreTypes = $types->reject(fn ($type) => $featuredTypes->has($type));
            $moreTypeSelected = $moreTypes->contains(request('type'));
        @endphp
        @include('partials.catalog-categories', ['categoryLabel' => 'Tour package categories'])

        <button type="button" class="filter-toggle" onclick="document.getElementById('filterPanel').classList.toggle('open')">
            <x-icon name="filter" /> Filters
        </button>

        <div class="catalog-layout">
            <aside class="filter-panel" id="filterPanel">
                <h3>Filter results</h3>
                <form method="GET" action="{{ route('packages.index') }}">
                    <input type="hidden" name="type" value="{{ request('type') }}">

                    <div class="field">
                        <label for="q">Search by name</label>
                        <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="e.g. Samal">
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
                            <option value="price_low" @selected(request('sort') === 'price_low')>Price: Low to High</option>
                            <option value="price_high" @selected(request('sort') === 'price_high')>Price: High to Low</option>
                            <option value="name" @selected(request('sort') === 'name')>Name (A&ndash;Z)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-poster-primary btn-block">Apply Filters</button>
                    @if (request()->anyFilled(['q', 'region_id', 'price_tier', 'type']))
                        <a href="{{ route('packages.index') }}" class="btn btn-poster-ghost btn-block" style="margin-top:8px;">Clear all</a>
                    @endif
                </form>
            </aside>

            <div>
                <div class="results-bar">
                    <div class="results-count">{{ $packages->total() }} package{{ $packages->total() === 1 ? '' : 's' }} found</div>
                </div>

                @if ($packages->count())
                    <div class="card-grid">
                        @foreach ($packages as $package)
                            @include('partials.listing-poster-card', ['listing' => $package])
                        @endforeach
                    </div>

                    <div class="pagination">
                        @if ($packages->onFirstPage())
                            <span class="disabled">&laquo;</span>
                        @else
                            <a href="{{ $packages->previousPageUrl() }}">&laquo;</a>
                        @endif

                        @foreach ($packages->getUrlRange(1, $packages->lastPage()) as $page => $url)
                            <span class="{{ $page === $packages->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></span>
                        @endforeach

                        @if ($packages->hasMorePages())
                            <a href="{{ $packages->nextPageUrl() }}">&raquo;</a>
                        @else
                            <span class="disabled">&raquo;</span>
                        @endif
                    </div>
                @else
                    <div class="empty-state">
                        <p><strong>No packages match your filters.</strong></p>
                        <a href="{{ route('packages.index') }}" class="btn btn-outline">Clear filters</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
