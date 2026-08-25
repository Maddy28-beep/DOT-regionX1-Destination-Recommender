@extends('layouts.app')

@section('title', 'Destinations — ExploreDVO')

@section('content')
<div class="page-head">
    <div class="container">
        <h1>Explore Davao Region Destinations</h1>
        <p>Browsing DOT-accredited destinations &mdash; verified for quality, safety, and authentic experience.</p>
    </div>
</div>

<div class="section-tight">
    <div class="container">

        <div class="chip-row">
            <a href="{{ request()->fullUrlWithQuery(['type' => null, 'page' => null]) }}" class="chip {{ request('type') ? '' : 'active' }}">All Types</a>
            @foreach ($types as $t)
                <a href="{{ request()->fullUrlWithQuery(['type' => $t, 'page' => null]) }}" class="chip {{ request('type') === $t ? 'active' : '' }}">{{ $t }}</a>
            @endforeach
        </div>

        <button type="button" class="filter-toggle" onclick="document.getElementById('filterPanel').classList.toggle('open')">
            <x-icon name="filter" /> Filters
        </button>

        <div class="catalog-layout">
            <aside class="filter-panel" id="filterPanel">
                <h3>Filter results</h3>
                <form method="GET" action="{{ route('destinations.index') }}">
                    <input type="hidden" name="type" value="{{ request('type') }}">

                    <div class="field">
                        <label for="q">Search by name</label>
                        <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="e.g. Samal Island">
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
                            <option value="Free" @selected(request('price_tier') === 'Free')>Free</option>
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
                            <option value="nearest" @selected(request('sort') === 'nearest')>Nearest First</option>
                            <option value="name" @selected(request('sort') === 'name')>Name (A&ndash;Z)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Apply Filters</button>
                    @if (request()->anyFilled(['q', 'region_id', 'price_tier', 'type']))
                        <a href="{{ route('destinations.index') }}" class="btn btn-ghost btn-block" style="margin-top:8px;">Clear all</a>
                    @endif
                </form>
            </aside>

            <div>
                <div class="results-bar">
                    <div class="results-count">{{ $destinations->total() }} destination{{ $destinations->total() === 1 ? '' : 's' }} found</div>
                </div>

                @if ($destinations->count())
                    <div class="card-grid">
                        @foreach ($destinations as $destination)
                            @include('partials.dest-card', ['destination' => $destination])
                        @endforeach
                    </div>

                    <div class="pagination">
                        @if ($destinations->onFirstPage())
                            <span class="disabled">&laquo;</span>
                        @else
                            <a href="{{ $destinations->previousPageUrl() }}">&laquo;</a>
                        @endif

                        @foreach ($destinations->getUrlRange(1, $destinations->lastPage()) as $page => $url)
                            <span class="{{ $page === $destinations->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></span>
                        @endforeach

                        @if ($destinations->hasMorePages())
                            <a href="{{ $destinations->nextPageUrl() }}">&raquo;</a>
                        @else
                            <span class="disabled">&raquo;</span>
                        @endif
                    </div>
                @else
                    <div class="empty-state">
                        <p><strong>No destinations match your filters.</strong></p>
                        <p>Try clearing some filters or searching a different keyword.</p>
                        <a href="{{ route('destinations.index') }}" class="btn btn-outline">Clear filters</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
