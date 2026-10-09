@extends('layouts.app')

@section('title', 'Tour Operators — ExploreDVO')

@section('content')
<header class="catalog-banner catalog-banner--tour-operators">
    <div class="container">
        <h1 class="poster-title">Davao, Locally Guided</h1>
        <p>Find local tour operators for your next adventure.</p>
    </div>
</header>

<div class="section-tight">
    <div class="container">

        @php
            $featuredTypes = collect(['Nature & Adventure', 'Beach & Island', 'Adventure & Hiking', 'Cultural Heritage'])
                ->filter(fn ($type) => $specializations->contains($type))
                ->mapWithKeys(fn ($type) => [$type => $type]);
            $moreTypes = $specializations->reject(fn ($type) => $featuredTypes->has($type));
            $moreTypeSelected = $moreTypes->contains(request('specialization'));
        @endphp
        @include('partials.catalog-categories', [
            'categoryLabel' => 'Tour operator specializations',
            'categoryParam' => 'specialization',
            'allCategoriesLabel' => 'All Specializations',
            'moreCategoriesLabel' => 'More specializations',
        ])

        <button type="button" class="filter-toggle" onclick="document.getElementById('filterPanel').classList.toggle('open')">
            <x-icon name="filter" /> Filters
        </button>

        <div class="catalog-layout">
            @php $activeFilters = \App\Support\ActiveFilters::from(request(), $regions, 'specialization'); @endphp

            @include('partials.catalog-filters', [
                'route' => 'tour-operators.index',
                'placeholder' => 'e.g. Apo Summit Guides',
                'regions' => $regions,
                'activeFilters' => $activeFilters,
                'tiers' => [['Budget-Friendly', 'Budget-Friendly', '₱'], ['Mid-range', 'Mid-range', '₱₱'], ['Premium', 'Premium', '₱₱₱']],
                'anyBudget' => true,
                'categoryParam' => 'specialization',
            ])

            <div>
                @include('partials.catalog-toolbar', [
                    'results' => $tourOperators,
                    'noun' => 'tour operator',
                    'sortOptions' => ['recommended' => 'Recommended', 'rating' => 'Highest Rated', 'name' => 'Name (A–Z)'],
                    'activeFilters' => $activeFilters,
                    'viewToggle' => 'grid-map',
                ])

                @if ($tourOperators->count())
                    <div class="card-grid" data-view-panel="grid">
                        @foreach ($tourOperators as $tourOperator)
                            @include('partials.listing-poster-card', ['listing' => $tourOperator])
                        @endforeach
                    </div>

                    <div class="results-map-panel" data-view-panel="map" hidden>
                        @include('partials.listing-results-map', ['listings' => $tourOperators, 'id' => 'tour-operators-map'])
                    </div>

                    <div class="pagination" data-view-panel="grid">
                        @if ($tourOperators->onFirstPage())
                            <span class="disabled">&laquo;</span>
                        @else
                            <a href="{{ $tourOperators->previousPageUrl() }}">&laquo;</a>
                        @endif

                        @foreach ($tourOperators->getUrlRange(1, $tourOperators->lastPage()) as $page => $url)
                            <span class="{{ $page === $tourOperators->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></span>
                        @endforeach

                        @if ($tourOperators->hasMorePages())
                            <a href="{{ $tourOperators->nextPageUrl() }}">&raquo;</a>
                        @else
                            <span class="disabled">&raquo;</span>
                        @endif
                    </div>
                @else
                    <x-davo-empty-state title="No tour operators match your filters" message="Try another category or clear your filters to explore more options." :action-url="route('tour-operators.index')" action-label="Clear filters" />
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
