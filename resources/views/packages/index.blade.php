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
            @php $activeFilters = \App\Support\ActiveFilters::from(request(), $regions, 'type'); @endphp

            @include('partials.catalog-filters', [
                'route' => 'packages.index',
                'placeholder' => 'e.g. Samal',
                'regions' => $regions,
                'activeFilters' => $activeFilters,
                'tiers' => [['Budget-Friendly', 'Budget', '₱'], ['Mid-range', 'Mid-range', '₱₱'], ['Premium', 'Premium', '₱₱₱']],
                'categoryParam' => 'type',
            ])

            <div>
                @include('partials.catalog-toolbar', [
                    'results' => $packages,
                    'noun' => 'package',
                    'sortOptions' => ['recommended' => 'Recommended', 'rating' => 'Highest Rated', 'price_low' => 'Price: Low to High', 'price_high' => 'Price: High to Low', 'name' => 'Name (A–Z)'],
                    'activeFilters' => $activeFilters,
                ])

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
