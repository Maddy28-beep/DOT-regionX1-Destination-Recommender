@extends('layouts.app')

@section('title', 'Accommodations — ExploreDVO')

@section('content')
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

        <button type="button" class="filter-toggle" onclick="document.getElementById('filterPanel').classList.toggle('open')">
            <x-icon name="filter" /> Filters
        </button>

        <div class="catalog-layout">
            @php $activeFilters = \App\Support\ActiveFilters::from(request(), $regions, 'type'); @endphp

            @include('partials.catalog-filters', [
                'route' => 'accommodations.index',
                'placeholder' => 'e.g. Pearl Farm',
                'regions' => $regions,
                'activeFilters' => $activeFilters,
                'tiers' => [['Budget-Friendly', 'Budget-Friendly', '₱'], ['Mid-range', 'Mid-range', '₱₱'], ['Premium', 'Premium', '₱₱₱']],
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

                    <div class="pagination" data-view-panel="grid">
                        @if ($accommodations->onFirstPage())
                            <span class="disabled">&laquo;</span>
                        @else
                            <a href="{{ $accommodations->previousPageUrl() }}">&laquo;</a>
                        @endif

                        @foreach ($accommodations->getUrlRange(1, $accommodations->lastPage()) as $page => $url)
                            <span class="{{ $page === $accommodations->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></span>
                        @endforeach

                        @if ($accommodations->hasMorePages())
                            <a href="{{ $accommodations->nextPageUrl() }}">&raquo;</a>
                        @else
                            <span class="disabled">&raquo;</span>
                        @endif
                    </div>
                @else
                    <div class="empty-state">
                        <p><strong>No accommodations match your filters.</strong></p>
                        <a href="{{ route('accommodations.index') }}" class="btn btn-outline">Clear filters</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
