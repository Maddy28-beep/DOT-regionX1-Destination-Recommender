@extends('layouts.app')

@section('title', 'Souvenir Centers — ExploreDVO')

@section('content')
<div class="page-head">
    <div class="container">
        <span class="poster-kicker">something to bring home</span>
        <h1 class="poster-title">Souvenir Centers in the Davao Region</h1>
        <p>DOT-accredited shops for authentic local crafts and memorabilia.</p>
    </div>
</div>

<div class="section-tight">
    <div class="container">

        <button type="button" class="filter-toggle" onclick="document.getElementById('filterPanel').classList.toggle('open')">
            <x-icon name="filter" /> Filters
        </button>

        <div class="catalog-layout">
            <aside class="filter-panel" id="filterPanel">
                <h3>Filter results</h3>
                <form method="GET" action="{{ route('souvenir-centers.index') }}">
                    <div class="field">
                        <label for="q">Search by name</label>
                        <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="e.g. Aldevinco">
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
                        <label for="sort">Sort by</label>
                        <select id="sort" name="sort">
                            <option value="recommended" @selected(request('sort', 'recommended') === 'recommended')>Recommended</option>
                            <option value="rating" @selected(request('sort') === 'rating')>Highest Rated</option>
                            <option value="name" @selected(request('sort') === 'name')>Name (A&ndash;Z)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-poster-primary btn-block">Apply Filters</button>
                    @if (request()->anyFilled(['q', 'region_id']))
                        <a href="{{ route('souvenir-centers.index') }}" class="btn btn-poster-ghost btn-block" style="margin-top:8px;">Clear all</a>
                    @endif
                </form>
            </aside>

            <div>
                <div class="results-bar">
                    <div class="results-count">{{ $souvenirCenters->total() }} souvenir center{{ $souvenirCenters->total() === 1 ? '' : 's' }} found</div>
                </div>

                @if ($souvenirCenters->count())
                    <div class="card-grid">
                        @foreach ($souvenirCenters as $souvenirCenter)
                            @include('partials.listing-poster-card', ['listing' => $souvenirCenter])
                        @endforeach
                    </div>

                    <div class="pagination">
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
                    <div class="empty-state">
                        <p><strong>No souvenir centers match your filters.</strong></p>
                        <a href="{{ route('souvenir-centers.index') }}" class="btn btn-outline">Clear filters</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
