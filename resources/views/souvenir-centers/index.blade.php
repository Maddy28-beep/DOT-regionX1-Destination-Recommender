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

                    @include('partials.pagination', ['paginator' => $souvenirCenters, 'panel' => 'grid'])
                @else
                    <x-davo-empty-state title="No souvenir centers match your filters" message="Try another category or clear your filters to explore more options." :action-url="route('souvenir-centers.index')" action-label="Clear filters" />
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
