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
        @endphp
        @include('partials.catalog-categories', ['categoryLabel' => 'Destination categories'])

        <button type="button" class="filter-toggle" onclick="document.getElementById('filterPanel').classList.toggle('open')">
            <x-icon name="filter" /> Filters
        </button>

        <div class="catalog-layout">
            @php $activeFilters = \App\Support\ActiveFilters::from(request(), $regions, 'type'); @endphp

            @include('partials.catalog-filters', [
                'route' => 'destinations.index',
                'placeholder' => 'e.g. Samal Island',
                'regions' => $regions,
                'activeFilters' => $activeFilters,
                'tiers' => [['Free', 'Free', 'No fee'], ['Budget-Friendly', 'Budget', '₱'], ['Mid-range', 'Mid-range', '₱₱'], ['Premium', 'Premium', '₱₱₱']],
                'categoryParam' => 'type',
                'formId' => 'destinationFilters',
                'hidden' => [['name' => 'view', 'value' => request('view') === 'map' ? 'map' : 'grid', 'id' => 'destinationViewInput']],
                'clearParams' => request('view') === 'map' ? ['view' => 'map'] : [],
            ])

            <div>
                @include('partials.catalog-toolbar', [
                    'results' => $destinations,
                    'noun' => 'destination',
                    'sortOptions' => ['recommended' => 'Recommended', 'rating' => 'Highest Rated', 'nearest' => 'Nearest First', 'name' => 'Name (A–Z)'],
                    'activeFilters' => $activeFilters,
                    'formId' => 'destinationFilters',
                    'viewToggle' => 'destinations',
                ])

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
