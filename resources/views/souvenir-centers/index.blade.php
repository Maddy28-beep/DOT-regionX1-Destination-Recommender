@extends('layouts.app')

@section('title', 'Souvenir Centers — ExploreDVO')

@section('content')
<header class="catalog-banner catalog-banner--souvenir-centers">
    <div class="container">
        <h1 class="poster-title">Bring Home Davao</h1>
        <p>Find local crafts, keepsakes, and pasalubong.</p>
    </div>
</header>

<div class="section-tight">
    <div class="container">

        <button type="button" class="filter-toggle" onclick="document.getElementById('filterPanel').classList.toggle('open')">
            <x-icon name="filter" /> Filters
        </button>

        <div class="catalog-layout">
            @php $activeFilters = \App\Support\ActiveFilters::from(request(), $regions, null); @endphp

            @include('partials.catalog-filters', [
                'route' => 'souvenir-centers.index',
                'placeholder' => 'e.g. Aldevinco',
                'regions' => $regions,
                'activeFilters' => $activeFilters,
            ])

            <div>
                @include('partials.catalog-toolbar', [
                    'results' => $souvenirCenters,
                    'noun' => 'souvenir center',
                    'sortOptions' => ['recommended' => 'Recommended', 'rating' => 'Highest Rated', 'name' => 'Name (A–Z)'],
                    'activeFilters' => $activeFilters,
                    'viewToggle' => 'grid-map',
                ])

                @if ($souvenirCenters->count())
                    <div class="card-grid" data-view-panel="grid">
                        @foreach ($souvenirCenters as $souvenirCenter)
                            @include('partials.listing-poster-card', ['listing' => $souvenirCenter])
                        @endforeach
                    </div>

                    <div class="results-map-panel" data-view-panel="map" hidden>
                        @include('partials.listing-results-map', ['listings' => $souvenirCenters, 'id' => 'souvenir-centers-map'])
                    </div>

                    <div class="pagination" data-view-panel="grid">
                        @if ($souvenirCenters->onFirstPage())
                            <span class="disabled">&laquo;</span>
                        @else
                            <a href="{{ $souvenirCenters->previousPageUrl() }}">&laquo;</a>
                        @endif

                        @foreach ($souvenirCenters->getUrlRange(1, $souvenirCenters->lastPage()) as $page => $url)
                            <span class="{{ $page === $souvenirCenters->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></span>
                        @endforeach

                        @if ($souvenirCenters->hasMorePages())
                            <a href="{{ $souvenirCenters->nextPageUrl() }}">&raquo;</a>
                        @else
                            <span class="disabled">&raquo;</span>
                        @endif
                    </div>
                @else
                    <x-davo-empty-state title="No souvenir centers match your filters" message="Try another category or clear your filters to explore more options." :action-url="route('souvenir-centers.index')" action-label="Clear filters" />
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
