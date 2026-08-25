@extends('layouts.app')

@section('title', 'Tour Operators — ExploreDVO')

@section('content')
<div class="page-head">
    <div class="container">
        <h1>Tour Operators in the Davao Region</h1>
        <p>DOT-accredited travel and tour operators &mdash; the businesses behind the guided tours and packages on ExploreDVO.</p>
    </div>
</div>

<div class="section-tight">
    <div class="container">

        <div class="chip-row">
            <a href="{{ request()->fullUrlWithQuery(['specialization' => null, 'page' => null]) }}" class="chip {{ request('specialization') ? '' : 'active' }}">All Specializations</a>
            @foreach ($specializations as $s)
                <a href="{{ request()->fullUrlWithQuery(['specialization' => $s, 'page' => null]) }}" class="chip {{ request('specialization') === $s ? 'active' : '' }}">{{ $s }}</a>
            @endforeach
        </div>

        <button type="button" class="filter-toggle" onclick="document.getElementById('filterPanel').classList.toggle('open')">
            <x-icon name="filter" /> Filters
        </button>

        <div class="catalog-layout">
            <aside class="filter-panel" id="filterPanel">
                <h3>Filter results</h3>
                <form method="GET" action="{{ route('tour-operators.index') }}">
                    <input type="hidden" name="specialization" value="{{ request('specialization') }}">

                    <div class="field">
                        <label for="q">Search by name</label>
                        <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="e.g. Apo Summit Guides">
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
                            <option value="name" @selected(request('sort') === 'name')>Name (A&ndash;Z)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Apply Filters</button>
                    @if (request()->anyFilled(['q', 'region_id', 'price_tier', 'specialization']))
                        <a href="{{ route('tour-operators.index') }}" class="btn btn-ghost btn-block" style="margin-top:8px;">Clear all</a>
                    @endif
                </form>
            </aside>

            <div>
                <div class="results-bar">
                    <div class="results-count">{{ $tourOperators->total() }} tour operator{{ $tourOperators->total() === 1 ? '' : 's' }} found</div>
                </div>

                @if ($tourOperators->count())
                    <div class="card-grid">
                        @foreach ($tourOperators as $tourOperator)
                            @include('partials.tour-operator-card', ['tourOperator' => $tourOperator])
                        @endforeach
                    </div>

                    <div class="pagination">
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
                    <div class="empty-state">
                        <p><strong>No tour operators match your filters.</strong></p>
                        <a href="{{ route('tour-operators.index') }}" class="btn btn-outline">Clear filters</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
