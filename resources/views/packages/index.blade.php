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

        <div class="catalog-layout">
            @php $activeFilters = \App\Support\ActiveFilters::from(request(), $regions, 'type'); @endphp

            @include('partials.catalog-filters', [
                'route' => 'packages.index',
                'placeholder' => 'e.g. Samal',
                'regions' => $regions,
                'activeFilters' => $activeFilters,
                'tiers' => [['Budget-Friendly', 'Budget-Friendly', ''], ['Mid-range', 'Mid-range', ''], ['Premium', 'Premium', '']],
                'anyBudget' => true,
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

                    @include('partials.pagination', ['paginator' => $packages])
                @else
                    <x-davo-empty-state title="No packages match your filters" message="Try another category or clear your filters to explore more options." :action-url="route('packages.index')" action-label="Clear filters" />
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
