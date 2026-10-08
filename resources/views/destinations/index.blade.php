@extends('layouts.app')

@section('title', 'Destinations — ExploreDVO')

@section('content')
<header class="catalog-banner">
    <div class="container">
        <h1 class="poster-title">Discover Davao</h1>
        <p>Explore Davao's mountains, islands, and local wonders.</p>
    </div>
</header>

<div class="section-tight">
    <div class="container">

        @php
            $featuredTypes = collect([
                'Nature & Adventure' => 'Nature & Adventure',
                'Beach & Leisure' => 'Beach & Leisure',
                'Cultural Heritage' => 'Culture & Heritage',
                'Wellness & Spa' => 'Wellness & Spa',
            ])->filter(fn ($label, $type) => $types->contains($type));
            $moreTypes = $types->reject(fn ($type) => $featuredTypes->has($type));
            $moreTypeSelected = $moreTypes->contains(request('type'));

            // The filters that are switched on, for the "Showing" chips and the count on the button.
            $tierLabels = ['Free' => 'Free', 'Budget-Friendly' => 'Budget ₱', 'Mid-range' => 'Mid-range ₱₱', 'Premium' => 'Premium ₱₱₱'];
            $activeFilters = collect([
                'q' => request()->filled('q') ? 'Search: '.request('q') : null,
                'region_id' => request()->filled('region_id') ? ($regions->firstWhere('id', (int) request('region_id'))?->name) : null,
                'price_tier' => request()->filled('price_tier') ? ($tierLabels[request('price_tier')] ?? request('price_tier')) : null,
                'type' => request()->filled('type') ? request('type') : null,
                'interest' => request()->filled('interest') ? 'Interest: '.request('interest') : null,
            ])->filter();
            $filterCount = $activeFilters->count();
            $viewParam = request('view') === 'map' ? ['view' => 'map'] : [];
        @endphp
        @include('partials.catalog-categories', ['categoryLabel' => 'Destination categories'])

        <button type="button" class="filter-toggle" onclick="document.getElementById('filterPanel').classList.toggle('open')">
            <x-icon name="filter" /> Filters
        </button>

        <div class="catalog-layout">
            <aside class="filter-panel filter-panel--v2" id="filterPanel">
                <div class="filter-panel__head">
                    <h3>Filters</h3>
                    @if ($filterCount)
                        <a href="{{ route('destinations.index', $viewParam) }}" class="filter-panel__clear">Clear all</a>
                    @endif
                </div>

                <form method="GET" action="{{ route('destinations.index') }}" id="destinationFilters" data-filter-form>
                    <input type="hidden" name="view" value="{{ request('view') === 'map' ? 'map' : 'grid' }}" id="destinationViewInput">
                    <input type="hidden" name="type" value="{{ request('type') }}" data-filter-count>

                    <div class="field">
                        <label for="q">Search by name</label>
                        <div class="input-icon">
                            <x-icon name="search" />
                            <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="e.g. Samal Island" data-filter-count>
                        </div>
                    </div>

                    <div class="field">
                        <label for="region_id">Province or city</label>
                        <select id="region_id" name="region_id" data-filter-count>
                            <option value="">All of Davao Region</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region->id }}" @selected(request('region_id') == $region->id)>{{ $region->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <fieldset class="field budget-field">
                        <legend>Budget</legend>
                        <div class="budget-tiles">
                            @foreach ([['Free', 'Free', 'No fee'], ['Budget-Friendly', 'Budget', '₱'], ['Mid-range', 'Mid-range', '₱₱'], ['Premium', 'Premium', '₱₱₱']] as [$value, $name, $sub])
                                <label class="budget-tile">
                                    <input type="radio" name="price_tier" value="{{ $value }}" @checked(request('price_tier') === $value) data-filter-count>
                                    <span class="budget-tile__name">{{ $name }}</span>
                                    <span class="budget-tile__sub">{{ $sub }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <button type="submit" class="btn btn-poster-primary btn-block" data-filter-submit>
                        Show results{{ $filterCount ? ' ('.$filterCount.' '.\Illuminate\Support\Str::plural('filter', $filterCount).')' : '' }}
                    </button>
                </form>
            </aside>

            <div>
                <div class="results-toolbar">
                    <div class="results-count">{{ $destinations->total() }} destination{{ $destinations->total() === 1 ? '' : 's' }} found</div>

                    <div class="results-toolbar__controls">
                        <label class="sort-control">
                            <span>Sort by</span>
                            <select name="sort" form="destinationFilters" onchange="this.form.submit()">
                                <option value="recommended" @selected(request('sort', 'recommended') === 'recommended')>Recommended</option>
                                <option value="rating" @selected(request('sort') === 'rating')>Highest Rated</option>
                                <option value="nearest" @selected(request('sort') === 'nearest')>Nearest First</option>
                                <option value="name" @selected(request('sort') === 'name')>Name (A&ndash;Z)</option>
                            </select>
                        </label>

                        <div class="destination-view-toggle" role="group" aria-label="Destination display" hidden>
                            <button type="button" data-destination-view="grid" aria-pressed="true"><x-icon name="grid" /> Grid</button>
                            <button type="button" data-destination-view="map" aria-pressed="false"><x-icon name="map" /> Map</button>
                        </div>
                    </div>

                    @if ($filterCount)
                        <div class="active-filters">
                            <span>Showing:</span>
                            @foreach ($activeFilters as $key => $label)
                                <a href="{{ request()->fullUrlWithQuery([$key => null, 'page' => null]) }}" class="filter-chip" aria-label="Remove filter: {{ $label }}">{{ $label }} <span aria-hidden="true">&times;</span></a>
                            @endforeach
                        </div>
                    @endif
                </div>

                @include('destinations.map-explorer')
                <div id="destinationListView">
                @if ($destinations->count())
                    <div class="card-grid">
                        @foreach ($destinations as $destination)
                            @include('partials.listing-poster-card', ['listing' => $destination])
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
</div>
@endsection
